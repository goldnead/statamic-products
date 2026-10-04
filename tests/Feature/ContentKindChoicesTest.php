<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\ContentKinds;
use Goldnead\StatamicProducts\Support\RefTarget;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Arten der Website mit Namensauswahl.
 *
 * Eine Art, die die Website anmeldet, war bisher ein Textfeld: wer einen
 * Verweis eintragen wollte, musste die Kennung kennen. Mit einer Quelle fuer
 * Optionen waehlt das Formular nach Namen; ohne bleibt alles wie zuvor.
 */
class ContentKindChoicesTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function create(array $contents): Access
    {
        return Access::create(['handle' => 'akademie', 'name' => 'Akademie', 'contents' => $contents]);
    }

    #[Test]
    public function a_kind_without_options_keeps_the_text_field(): void
    {
        ContentKinds::register('community', 'Community-Bereich', 'Space (Kennung)');

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/new')
            ->assertInertia(fn ($page) => $page
                ->where('form.kinds.4.value', 'community')
                ->missing('form.choices.community'));
    }

    #[Test]
    public function a_kind_with_options_is_offered_by_name(): void
    {
        ContentKinds::register('library', 'Bibliothek', 'Bibliothek', options: fn () => [
            'lib-2' => 'Stimmbildung',
            'lib-1' => 'Atemarbeit',
        ]);

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/new')
            ->assertInertia(fn ($page) => $page
                ->where('form.choices.library', [
                    ['value' => 'lib-2', 'label' => 'Stimmbildung'],
                    ['value' => 'lib-1', 'label' => 'Atemarbeit'],
                ]));
    }

    #[Test]
    public function the_options_are_read_when_the_form_opens_not_at_registration(): void
    {
        $calls = 0;
        ContentKinds::register('library', 'Bibliothek', options: function () use (&$calls) {
            $calls++;

            return ['a' => 'A'];
        });

        $this->assertSame(0, $calls);

        $this->actingAs($this->user())->get('/cp/utilities/product-accesses/new')->assertOk();

        $this->assertSame(1, $calls);
    }

    #[Test]
    public function a_failing_source_is_logged_and_falls_back_to_free_text(): void
    {
        Log::spy();
        ContentKinds::register('library', 'Bibliothek', options: function () {
            throw new \RuntimeException('Collection weg');
        });

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/new')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('form.choices.library'));

        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function a_source_that_returns_something_else_than_a_list_counts_as_failed(): void
    {
        Log::spy();
        ContentKinds::register('library', 'Bibliothek', options: fn () => 'kaputt');

        $this->assertNull(ContentKinds::choices('library'));
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function an_empty_source_falls_back_to_free_text_without_a_warning(): void
    {
        Log::spy();
        ContentKinds::register('library', 'Bibliothek', options: fn () => []);

        $this->assertNull(ContentKinds::choices('library'));
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function values_are_strings_even_when_the_source_uses_integer_keys(): void
    {
        ContentKinds::register('library', 'Bibliothek', options: fn () => [7 => 'Sieben']);

        $this->assertSame([['value' => '7', 'label' => 'Sieben']], ContentKinds::choices('library'));
    }

    #[Test]
    public function built_in_kinds_and_unknown_kinds_have_no_registered_choices(): void
    {
        $this->assertNull(ContentKinds::choices('course'));
        $this->assertNull(ContentKinds::choices('gibt-es-nicht'));
    }

    #[Test]
    public function registering_again_without_options_drops_the_old_source(): void
    {
        ContentKinds::register('library', 'Bibliothek', options: fn () => ['a' => 'A']);
        ContentKinds::register('library', 'Bibliothek');

        $this->assertNull(ContentKinds::choices('library'));
    }

    #[Test]
    public function without_a_resolver_a_site_kind_stays_unknowable(): void
    {
        ContentKinds::register('library', 'Bibliothek', options: fn () => ['a' => 'A']);

        $this->assertSame(RefTarget::UNKNOWABLE, RefTarget::forContent('library', 'a')->state);
    }

    #[Test]
    public function a_resolver_can_answer_resolved_gone_or_cannot_be_checked(): void
    {
        ContentKinds::register('library', 'Bibliothek', resolver: function (string $ref) {
            return match ($ref) {
                'da' => 'Atemarbeit',
                'weg' => null,
                'wohl' => RefTarget::unknowable(),
                'objekt' => RefTarget::resolved('Objekt'),
                default => throw new \RuntimeException('kaputt'),
            };
        });

        $resolved = RefTarget::forContent('library', 'da');
        $this->assertSame(RefTarget::RESOLVED, $resolved->state);
        $this->assertSame('Atemarbeit', $resolved->label);

        $this->assertSame(RefTarget::MISSING, RefTarget::forContent('library', 'weg')->state);
        $this->assertSame(RefTarget::UNKNOWABLE, RefTarget::forContent('library', 'wohl')->state);
        $this->assertSame('Objekt', RefTarget::forContent('library', 'objekt')->label);
    }

    #[Test]
    public function a_throwing_resolver_is_unknowable_and_logged_and_not_remembered(): void
    {
        Log::spy();
        $calls = 0;
        ContentKinds::register('library', 'Bibliothek', resolver: function () use (&$calls) {
            $calls++;

            throw new \RuntimeException('kaputt');
        });

        $this->assertSame(RefTarget::UNKNOWABLE, RefTarget::forContent('library', 'x')->state);
        $this->assertSame(RefTarget::UNKNOWABLE, RefTarget::forContent('library', 'x')->state);

        $this->assertSame(2, $calls);
        Log::shouldHaveReceived('warning')->twice();
    }

    #[Test]
    public function a_gone_pointer_shows_on_the_detail_page_and_in_the_missing_count(): void
    {
        ContentKinds::register('library', 'Bibliothek', resolver: fn (string $ref) => $ref === 'lib-1' ? 'Atemarbeit' : null);

        $access = $this->create([
            ['kind' => 'library', 'ref' => 'lib-1'],
            ['kind' => 'library', 'ref' => 'lib-weg'],
        ]);

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertInertia(fn ($page) => $page
                ->where('access.targets.library|lib-1.state', 'resolved')
                ->where('access.targets.library|lib-1.label', 'Atemarbeit')
                ->where('access.targets.library|lib-weg.state', 'missing'));
    }

    #[Test]
    public function saving_a_pointer_that_is_not_in_the_options_still_works(): void
    {
        ContentKinds::register('library', 'Bibliothek', options: fn () => ['lib-1' => 'Atemarbeit']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', [
                'handle' => 'akademie',
                'name' => 'Akademie',
                'contents' => [['kind' => 'library', 'ref' => 'lib-alt']],
            ])
            ->assertRedirect();

        $this->assertSame('lib-alt', Access::firstWhere('handle', 'akademie')->contents[0]['ref']);
    }

    #[Test]
    public function material_is_labelled_in_the_course_list_and_courses_are_not(): void
    {
        $this->stubCourses();
        app()->setLocale('de');

        Collection::make('courses')->save();
        Entry::make()->collection('courses')->id('k1')->slug('cvt-101')->data(['title' => 'CVT 101'])->save();
        Entry::make()->collection('courses')->id('k2')->slug('alt')->data(['title' => 'Alt', 'kind' => 'course'])->save();
        Entry::make()->collection('courses')->id('m1')->slug('baraye')->data(['title' => 'Baraye Arrangement', 'kind' => 'material'])->save();

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/new')
            ->assertInertia(fn ($page) => $page
                ->where('form.choices.course', [
                    ['value' => 'k2', 'label' => 'Alt'],
                    ['value' => 'm1', 'label' => 'Baraye Arrangement (Material)'],
                    ['value' => 'k1', 'label' => 'CVT 101'],
                ]));
    }

    #[Test]
    public function the_material_label_follows_the_cp_language(): void
    {
        $this->stubCourses();
        Collection::make('courses')->save();
        Entry::make()->collection('courses')->id('m1')->slug('baraye')->data(['title' => 'Baraye', 'kind' => 'material'])->save();

        app()->setLocale('en');

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/new')
            ->assertInertia(fn ($page) => $page
                ->where('form.choices.course.0.label', 'Baraye (material)'));
    }

    /** Das Addon fragt nur, ob die Klasse da ist; mehr braucht es von statamic-courses nicht. */
    private function stubCourses(): void
    {
        if (! class_exists('\Goldnead\Courses\ServiceProvider')) {
            eval('namespace Goldnead\Courses; class ServiceProvider {}');
        }
    }
}
