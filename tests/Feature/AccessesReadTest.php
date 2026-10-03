<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\Accesses;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Die Lese-API fuer die Website: `Accesses::find($slug)`.
 *
 * Laeuft ohne statamic-entitlements; die Website liest hier auch dann, wenn
 * sie ihren eigenen PackageResolver bindet.
 */
class AccessesReadTest extends TestCase
{
    protected function access(string $handle, array $contents = [], array $overrides = []): Access
    {
        return Access::query()->create(array_merge([
            'handle' => $handle,
            'name' => ucfirst($handle),
            'active' => true,
            'contents' => $contents,
        ], $overrides));
    }

    #[Test]
    public function an_unknown_slug_is_null(): void
    {
        $this->assertNull(Accesses::find('gibt-es-nicht'));
        $this->assertNull(Accesses::find(''));
    }

    #[Test]
    public function expand_lists_every_slug_a_grant_covers(): void
    {
        Collection::make('courses')->save();
        Entry::make()->collection('courses')->slug('cvt-101')->id('kurs-1')->save();

        $this->access('innen', [['kind' => 'course', 'ref' => 'kurs-1'], ['kind' => 'community', 'ref' => 'raum']]);
        $this->access('mitte', [['kind' => 'access', 'ref' => 'innen']]);
        $this->access('aussen', [['kind' => 'access', 'ref' => 'mitte'], ['kind' => 'event', 'ref' => 'uuid-1']]);

        $this->assertEqualsCanonicalizing(
            ['mitte', 'innen', 'kurs-1', 'cvt-101', 'raum', 'uuid-1'],
            Accesses::find('aussen')->expand(),
        );
    }

    #[Test]
    public function expand_stops_at_an_inactive_access_and_in_a_cycle(): void
    {
        $this->access('innen', [['kind' => 'community', 'ref' => 'raum']], ['active' => false]);
        $this->access('a', [['kind' => 'access', 'ref' => 'b'], ['kind' => 'access', 'ref' => 'innen']]);
        $this->access('b', [['kind' => 'access', 'ref' => 'a']]);

        $this->assertEqualsCanonicalizing(['b', 'innen'], Accesses::find('a')->expand());
        // Inaktiv: gefunden (fuer Guthaben beim Nachspielen), aber es oeffnet nichts.
        $this->assertSame([], Accesses::find('innen')->expand());
        $this->assertFalse(Accesses::find('innen')->active());
    }

    #[Test]
    public function credit_lines_come_only_from_the_access_itself(): void
    {
        $this->access('innen', [], ['credits' => [['session_type' => 'gruppe', 'kind' => 'one_time', 'count' => 6]]]);
        $this->access('aussen', [['kind' => 'access', 'ref' => 'innen']], ['credits' => [
            ['session_type' => 'einzel', 'kind' => 'one_time', 'count' => 3],
            ['session_type' => 'einzel', 'kind' => 'one_time', 'count' => 1, 'ended_at' => '2026-10-01T00:00:00+00:00'],
        ]]);

        $lines = Accesses::find('aussen')->creditLines();

        $this->assertSame([0], array_column($lines, 'line'));
        $this->assertSame([3], array_column($lines, 'count'));

        // Fuer das Nachspielen einer alten Vergabe: mit den beendeten.
        $this->assertSame([0, 1], array_column(Accesses::find('aussen')->creditLines(includeEnded: true), 'line'));
    }

    #[Test]
    public function contents_of_a_kind_include_nested_accesses_in_order(): void
    {
        $this->access('innen', [['kind' => 'course', 'ref' => 'kurs-2'], ['kind' => 'course', 'ref' => 'kurs-1']]);
        $this->access('weg', [['kind' => 'course', 'ref' => 'kurs-9']], ['active' => false]);
        $this->access('aussen', [
            ['kind' => 'course', 'ref' => 'kurs-1', 'label' => 'Erster'],
            ['kind' => 'access', 'ref' => 'innen'],
            ['kind' => 'access', 'ref' => 'weg'],
            ['kind' => 'file', 'ref' => 'assets::a.pdf'],
        ]);

        $courses = Accesses::find('aussen')->contentsOf('course');

        $this->assertSame(['kurs-1', 'kurs-2'], array_column($courses, 'ref'));
        $this->assertSame('Erster', $courses[0]['label']);
        $this->assertSame(['assets::a.pdf'], array_column(Accesses::find('aussen')->contentsOf('file'), 'ref'));
    }

    #[Test]
    public function a_save_is_visible_to_the_next_read(): void
    {
        $this->access('paket', [['kind' => 'community', 'ref' => 'raum']]);
        $this->assertSame(['raum'], Accesses::find('paket')->expand());

        Access::query()->where('handle', 'paket')->firstOrFail()->update(['contents' => [['kind' => 'community', 'ref' => 'saal']]]);

        $this->assertSame(['saal'], Accesses::find('paket')->expand());
    }
}
