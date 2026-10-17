<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\AccessGraph;
use Goldnead\StatamicProducts\Support\AccessView;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * `added_at` je Guthabenzeile (backlog-products-guthabenzeile-added-at).
 *
 * Wer eine Zeile an einem schon vergebenen Zugang anlegt, darf damit keine
 * fruehere Vergabe veraendern: die Zeile traegt ihren Anlegezeitpunkt, und wer
 * eine Vergabe pruefen oder nachspielen will, fragt mit deren Zeitpunkt.
 */
class CreditLineAddedAtTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function access(array $credits = []): Access
    {
        return Access::create([
            'handle' => 'choiraccelerator',
            'name' => 'ChoirAccelerator',
            'credits' => $credits ?: [
                ['session_type' => 'einzel', 'kind' => 'one_time', 'count' => 2],
            ],
        ]);
    }

    protected function grant(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('product_slug', 191);
        });
        DB::table('entitlements')->insert(['product_slug' => 'choiraccelerator']);
    }

    #[Test]
    public function a_new_line_carries_the_time_it_was_added(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');

        $access = $this->access();

        $this->assertSame(Carbon::now()->toIso8601String(), $access->fresh()->credits[0]['added_at']);
    }

    #[Test]
    public function a_line_with_a_preset_number_from_the_takeover_has_no_added_at(): void
    {
        $access = $this->access([['line' => 7, 'session_type' => 'einzel', 'kind' => 'one_time', 'count' => 2]]);

        $this->assertArrayNotHasKey('added_at', $access->fresh()->credits[0]);
        $this->assertNull($access->fresh()->credits[0]['added_at'] ?? null);
    }

    #[Test]
    public function a_line_added_after_a_grant_is_not_part_of_that_grant(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $access = $this->access([['line' => 0, 'session_type' => 'einzel', 'kind' => 'one_time', 'count' => 2]]);
        $this->grant();

        $grantedAt = Carbon::parse('2026-10-02 09:00:00');

        Carbon::setTestNow('2026-10-05 12:00:00');
        $credits = $access->fresh()->credits;
        $credits[] = ['session_type' => 'gruppe', 'kind' => 'one_time', 'count' => 6];
        $access->fresh()->update(['credits' => $credits]);

        $access = $access->fresh();

        $this->assertCount(2, $access->creditLines());
        $this->assertSame([0], array_column($access->creditLinesAt($grantedAt), 'line'));

        // Eine Vergabe nach dem Anlegen bekommt beide.
        $this->assertSame([0, 1], array_column($access->creditLinesAt(Carbon::parse('2026-10-06 00:00:00')), 'line'));

        // Gleiches ueber den Leser, den die Website benutzt.
        $this->assertCount(1, (new AccessView($access, app(AccessGraph::class)))->creditLines(true, $grantedAt));
    }

    #[Test]
    public function an_existing_line_keeps_its_added_at_when_the_set_is_saved_again(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $access = $this->access();
        $first = $access->fresh()->credits[0]['added_at'];

        Carbon::setTestNow('2026-10-09 10:00:00');
        $credits = $access->fresh()->credits;
        unset($credits[0]['added_at']); // ein Formular, das es nicht kennt
        $credits[0]['count'] = 3;
        $access->fresh()->update(['credits' => $credits]);

        $this->assertSame($first, $access->fresh()->credits[0]['added_at']);

        $credits = $access->fresh()->credits;
        $credits[0]['added_at'] = '2030-01-01T00:00:00+00:00'; // ein Versuch, es zu verschieben
        $access->fresh()->update(['credits' => $credits]);

        $this->assertSame($first, $access->fresh()->credits[0]['added_at']);
    }

    #[Test]
    public function the_cp_form_does_not_drop_added_at(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $access = $this->access();
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();
        $first = $access->fresh()->credits[0]['added_at'];

        Carbon::setTestNow('2026-10-09 10:00:00');
        $props = $this->actingAs($user)->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertOk()->viewData('page')['props'];
        $form = array_merge($props['access']['values'], ['version' => $props['access']['version']]);
        $form['credits'][] = ['session_type' => 'gruppe', 'kind' => 'one_time', 'count' => 6];

        $this->actingAs($user)->patchJson('/cp/utilities/product-accesses/'.$access->id, $form)->assertRedirect();

        $saved = $access->fresh()->credits;
        $this->assertSame($first, $saved[0]['added_at']);
        $this->assertSame(Carbon::now()->toIso8601String(), $saved[1]['added_at']);
    }
}
