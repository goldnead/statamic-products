<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Die Garantien hinter den Guthabenzeilen, auch ausserhalb des Formulars.
 *
 * Die Uebernahme (Z3) und der Circle-Import (Z6) schreiben ueber das Modell,
 * nicht ueber den Controller. Was hier steht, muss deshalb im Modell gelten:
 * eine Zeilennummer ist der `<index>` im Idempotenzschluessel der Website, und
 * eine doppelt vergebene Nummer schreibt Guthaben einmal statt zweimal gut,
 * ohne dass es jemand merkt.
 *
 * Dazu das gleichzeitige Speichern aus zwei Tabs: der zweite Stand darf den
 * ersten nicht still ueberschreiben.
 */
class AccessIntegrityTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function entitlementsTable(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('product_slug', 191);
        });
    }

    protected function grant(string $slug): void
    {
        DB::table('entitlements')->insert(['product_slug' => $slug]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function twoLines(): array
    {
        return [
            ['session_type' => 'einzel', 'kind' => 'one_time', 'count' => 2],
            ['session_type' => 'gruppe', 'kind' => 'one_time', 'count' => 6],
        ];
    }

    protected function access(array $overrides = []): Access
    {
        return Access::create(array_merge([
            'handle' => 'choiraccelerator',
            'name' => 'ChoirAccelerator',
            'credits' => $this->twoLines(),
        ], $overrides));
    }

    /**
     * Was das Formular beim Laden bekam, und wie es den Stand zurueckschickt.
     *
     * @return array<string, mixed>
     */
    protected function form(Access $access): array
    {
        $page = $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertOk();

        $props = $page->viewData('page')['props'];

        return array_merge($props['access']['values'], ['version' => $props['access']['version']]);
    }

    // ---- Zwei Tabs ----------------------------------------------------------

    #[Test]
    public function a_stale_form_is_refused_instead_of_overwriting_the_newer_state(): void
    {
        $access = $this->access();

        $tabA = $this->form($access);
        $tabB = $this->form($access);

        // Tab B legt eine Zeile an und speichert zuerst.
        $tabB['credits'][] = ['session_type' => 'b', 'kind' => 'one_time', 'count' => 1];
        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, $tabB)
            ->assertRedirect();

        // Tab A kennt Zeile 2 nicht und legt ebenfalls eine an.
        $tabA['credits'][] = ['session_type' => 'a', 'kind' => 'one_time', 'count' => 1];
        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, $tabA)
            ->assertStatus(409)
            ->assertJsonValidationErrors('version');

        $fresh = $access->fresh();
        $this->assertSame([0, 1, 2], array_column($fresh->credits, 'line'));
        $this->assertSame('b', $fresh->credits[2]['session_type']);
        $this->assertSame(3, $fresh->credit_lines_issued);
    }

    #[Test]
    public function a_current_form_saves_and_gets_a_new_version(): void
    {
        $access = $this->access();
        $form = $this->form($access);
        $form['name'] = 'Neu';

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, $form)
            ->assertRedirect();

        $this->assertSame('Neu', $access->fresh()->name);
        $this->assertNotSame($form['version'], $this->form($access)['version']);
    }

    // ---- Modell: Zaehler und Nummern -------------------------------------------

    #[Test]
    public function the_counter_never_falls_behind_the_stored_one(): void
    {
        $access = $this->access();

        // Zwei Kopien desselben Datensatzes, wie zwei Prozesse sie halten.
        $first = Access::find($access->id);
        $second = Access::find($access->id);

        $first->update(['credits' => [...$first->credits, ['session_type' => 'x', 'kind' => 'one_time', 'count' => 1]]]);

        // Die zweite Kopie kennt Zeile 2 nicht. Ihre neue Zeile darf trotzdem
        // nicht 2 heissen, und der Zaehler nicht auf 3 zurueckfallen.
        $second->update(['credits' => [...$second->credits, ['session_type' => 'y', 'kind' => 'one_time', 'count' => 1]]]);

        $fresh = $access->fresh();
        $this->assertSame([0, 1, 3], array_column($fresh->credits, 'line'));
        $this->assertSame(4, $fresh->credit_lines_issued);
    }

    #[Test]
    public function the_model_refuses_the_same_line_twice(): void
    {
        $this->expectException(ValidationException::class);

        Access::create([
            'handle' => 'doppelt',
            'name' => 'Doppelt',
            'credits' => [
                ['line' => 0, 'session_type' => 'a', 'kind' => 'one_time', 'count' => 1],
                ['line' => 0, 'session_type' => 'b', 'kind' => 'one_time', 'count' => 1],
            ],
        ]);
    }

    #[Test]
    public function the_model_refuses_a_line_number_that_was_handed_out_before(): void
    {
        $access = $this->access();

        // Zeile 1 weg (noch nicht vergeben, also erlaubt) ...
        $access->update(['credits' => [$access->credits[0]]]);
        $this->assertSame(2, $access->fresh()->credit_lines_issued);

        // ... und dieselbe Nummer von Hand wieder hinein: verboten.
        try {
            $access->fresh()->update(['credits' => [
                $access->fresh()->credits[0],
                ['line' => 1, 'session_type' => 'neu', 'kind' => 'one_time', 'count' => 1],
            ]]);
            $this->fail('Eine schon vergebene Nummer wurde neu benutzt.');
        } catch (ValidationException) {
            // erwartet
        }

        $this->assertSame([0], array_column($access->fresh()->credits, 'line'));
    }

    // ---- Modell: nach einer Vergabe -------------------------------------------

    #[Test]
    public function the_model_refuses_renaming_a_granted_access(): void
    {
        $this->entitlementsTable();
        $access = $this->access();
        $this->grant('choiraccelerator');

        try {
            $access->update(['handle' => 'umbenannt']);
            $this->fail('Ein vergebener Slug wurde umbenannt.');
        } catch (ValidationException) {
            // erwartet
        }

        $this->assertSame('choiraccelerator', $access->fresh()->handle);
    }

    #[Test]
    public function the_model_refuses_dropping_a_line_of_a_granted_access(): void
    {
        $this->entitlementsTable();
        $access = $this->access();
        $this->grant('choiraccelerator');

        try {
            $access->update(['credits' => [$access->credits[0]]]);
            $this->fail('Eine vergebene Zeile wurde geloescht.');
        } catch (ValidationException) {
            // erwartet
        }

        $this->assertSame([0, 1], array_column($access->fresh()->credits, 'line'));
    }

    #[Test]
    public function an_ended_line_of_a_granted_access_stays_ended(): void
    {
        $this->entitlementsTable();
        $access = $this->access();
        $credits = $access->credits;
        $credits[1]['ended_at'] = now()->toIso8601String();
        $access->update(['credits' => $credits]);
        $this->grant('choiraccelerator');

        // Ueber das Modell ...
        $reopened = $access->fresh()->credits;
        $reopened[1]['ended_at'] = null;

        try {
            $access->fresh()->update(['credits' => $reopened]);
            $this->fail('Eine beendete Zeile wurde wieder geoeffnet.');
        } catch (ValidationException) {
            // erwartet
        }

        // ... und ueber das Formular.
        $form = $this->form($access);
        $form['credits'][1]['ended'] = false;

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, $form)
            ->assertJsonValidationErrors('credits.1.ended');

        $this->assertNotNull($access->fresh()->credits[1]['ended_at']);
    }

    #[Test]
    public function before_a_grant_an_ended_line_may_be_reopened(): void
    {
        $access = $this->access();
        $credits = $access->credits;
        $credits[1]['ended_at'] = now()->toIso8601String();
        $access->update(['credits' => $credits]);

        $form = $this->form($access);
        $form['credits'][1]['ended'] = false;

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, $form)
            ->assertRedirect();

        $this->assertNull($access->fresh()->credits[1]['ended_at']);
    }

    // ---- Zyklus ----------------------------------------------------------------

    #[Test]
    public function a_diamond_of_nested_accesses_is_not_a_cycle_and_stays_fast(): void
    {
        // Eine Kette aus Rauten: jeder Knoten zeigt auf zwei, die beide auf den
        // naechsten zeigen. Ohne Merkliste waeren das 2^20 Wege.
        for ($i = 20; $i >= 0; $i--) {
            $next = $i < 20 ? [['kind' => 'access', 'ref' => "l{$i}"], ['kind' => 'access', 'ref' => "r{$i}"]] : [];
            Access::create(['handle' => "n{$i}", 'name' => "N{$i}", 'contents' => $next]);
            Access::create(['handle' => 'l'.($i - 1), 'name' => 'L', 'contents' => [['kind' => 'access', 'ref' => "n{$i}"]]]);
            Access::create(['handle' => 'r'.($i - 1), 'name' => 'R', 'contents' => [['kind' => 'access', 'ref' => "n{$i}"]]]);
        }

        $started = microtime(true);
        $this->assertNull(Access::cycleThrough('neu', [['kind' => 'access', 'ref' => 'n0']]));
        $this->assertLessThan(2.0, microtime(true) - $started);

        $this->assertNotNull(Access::cycleThrough('n20', [['kind' => 'access', 'ref' => 'n0']]));
    }
}
