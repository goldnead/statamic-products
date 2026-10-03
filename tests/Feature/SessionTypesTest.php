<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\SessionTypes;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Sessiontypen: eine Registry der Website statt Freitext.
 *
 * Ein Tippfehler im Sessiontyp schreibt spaeter Guthaben auf einen Typ, den es
 * nicht gibt. Meldet die Website ihre Typen an, waehlt das Formular aus ihnen
 * und lehnt alles andere ab; meldet sie nichts an, bleibt es Freitext.
 */
class SessionTypesTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $credits
     * @return array<string, mixed>
     */
    protected function payload(array $credits): array
    {
        return ['handle' => 'accelerator', 'name' => 'Accelerator', 'credits' => $credits];
    }

    #[Test]
    public function without_a_registry_the_session_type_is_free_text(): void
    {
        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                ['session_type' => 'irgendwas', 'kind' => 'one_time', 'count' => 2],
            ]))
            ->assertRedirect();

        $access = Access::firstWhere('handle', 'accelerator');

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertInertia(fn ($page) => $page
                ->where('form.sessionTypes', [])
                ->where('access.sessionTargets.irgendwas.state', 'unknowable'));
    }

    #[Test]
    public function with_a_registry_an_unknown_session_type_is_refused(): void
    {
        SessionTypes::register('uuid-einzel', 'Einzelsession');

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                ['session_type' => 'uuid-einzle', 'kind' => 'one_time', 'count' => 2],
            ]))
            ->assertJsonValidationErrors('credits.0.session_type');

        $this->assertSame(0, Access::count());
    }

    #[Test]
    public function a_registered_type_is_offered_by_name_and_resolved(): void
    {
        SessionTypes::register('uuid-einzel', 'Einzelsession');
        SessionTypes::register('uuid-gruppe', 'Gruppensession');

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                ['session_type' => 'uuid-einzel', 'kind' => 'one_time', 'count' => 2],
                ['session_type' => 'uuid-gruppe', 'kind' => 'subscription', 'per_month' => 6],
            ]))
            ->assertRedirect();

        $access = Access::firstWhere('handle', 'accelerator');

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertInertia(fn ($page) => $page
                ->where('form.sessionTypes.0.label', 'Einzelsession')
                ->where('access.sessionTargets.uuid-einzel.state', 'resolved')
                ->where('access.sessionTargets.uuid-einzel.label', 'Einzelsession'));

        $this->actingAs($this->user())
            ->getJson('/cp/utilities/product-accesses')
            // Die Suite laeuft englisch; deutsch steht dort „je Monat".
            ->assertJsonPath('data.0.credits_summary', '2 × Einzelsession · 6 × Gruppensession per month');
    }

    #[Test]
    public function a_stored_type_the_site_no_longer_registers_stays_savable_and_shows_as_missing(): void
    {
        SessionTypes::register('uuid-einzel', 'Einzelsession');
        $access = Access::create($this->payload([
            ['session_type' => 'alt', 'kind' => 'one_time', 'count' => 1],
        ]));

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertInertia(fn ($page) => $page->where('access.sessionTargets.alt.state', 'missing'));

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, array_merge($this->payload([
                ['line' => 0, 'session_type' => 'alt', 'kind' => 'one_time', 'count' => 3],
            ]), ['name' => 'Neu']))
            ->assertRedirect();

        $this->assertSame(3, $access->fresh()->credits[0]['count']);
    }
}
