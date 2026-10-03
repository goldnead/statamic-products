<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\ContentKinds;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Zugaenge: was ein Kauf freischaltet, als eigener Datensatz.
 *
 * Die Regeln hier schuetzen Dinge, die ausserhalb dieses Addons weiterleben:
 * der Slug steht auf Vergaben in `entitlements`, und die Nummer einer
 * Guthabenzeile ist der `<index>` im Idempotenzschluessel, mit dem die Website
 * Guthaben gutschreibt. Wer eins davon nachtraeglich aendert, erzeugt doppeltes
 * oder verlorenes Guthaben, ohne dass ein Fehler entsteht.
 */
class AccessScreenTest extends TestCase
{
    protected $superuser = null;

    protected function tearDown(): void
    {
        ContentKinds::forget();

        parent::tearDown();
    }

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('ohne@example.com')->assignRole($role))->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function valid(array $overrides = []): array
    {
        return array_merge([
            'handle' => 'choiraccelerator',
            'name' => 'ChoirAccelerator',
            'active' => true,
            'opens_members_area' => false,
            'contents' => [],
            'credits' => [],
        ], $overrides);
    }

    protected function access(array $overrides = []): Access
    {
        return Access::create($this->valid($overrides));
    }

    /**
     * Die Tabelle von `statamic-entitlements`, nur mit der Spalte, die hier
     * gelesen wird. Das Paket selbst ist in dieser Suite nicht installiert, und
     * genau das ist der Normalfall, gegen den die Pruefung bestehen muss.
     */
    protected function entitlementsTable(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('product_slug', 191);
            $table->string('status', 16)->default('active');
        });
    }

    protected function grant(string $slug): void
    {
        DB::table('entitlements')->insert(['product_slug' => $slug]);
    }

    protected function url(?Access $access = null): string
    {
        return '/cp/utilities/product-accesses'.($access ? '/'.$access->id : '');
    }

    #[Test]
    public function an_access_with_every_kind_of_content_and_two_credit_lines_can_be_created(): void
    {
        ContentKinds::register('community', 'Community');

        $this->actingAs($this->user())
            ->postJson($this->url(), $this->valid([
                'description' => 'Zwölf Wochen mit dem Chor.',
                'cover' => 'assets::cover.jpg',
                'opens_members_area' => true,
                'contents' => [
                    ['kind' => 'access', 'ref' => 'stimmnotfallplan', 'label' => null],
                    ['kind' => 'course', 'ref' => 'kurs-vokale'],
                    ['kind' => 'file', 'ref' => 'assets::workbook.pdf', 'label' => 'Workbook'],
                    ['kind' => 'event', 'ref' => '7f1d1a2e-0000-4000-8000-000000000001'],
                    ['kind' => 'community', 'ref' => 'choiraccelerator-space'],
                ],
                'credits' => [
                    ['session_type' => 'einzel-uuid', 'kind' => 'one_time', 'count' => 2, 'valid_months' => 6],
                    ['session_type' => 'gruppe-uuid', 'kind' => 'one_time', 'count' => 6, 'valid_months' => 6],
                ],
            ]))
            ->assertRedirect();

        $access = Access::firstWhere('handle', 'choiraccelerator');

        $this->assertTrue($access->opens_members_area);
        $this->assertSame('assets::cover.jpg', $access->cover);
        $this->assertSame(['access', 'course', 'file', 'event', 'community'], array_column($access->contents, 'kind'));
        $this->assertSame('Workbook', $access->contents[2]['label']);

        // Die Nummern vergibt der Server, ab null, in der Reihenfolge des Formulars:
        // genau der heutige Index aus `PACKAGE_MAPPING` (0 Einzel, 1 Gruppe).
        $this->assertSame([0, 1], array_column($access->credits, 'line'));
        $this->assertSame([2, 6], array_column($access->credits, 'count'));
        $this->assertNull($access->credits[0]['per_month']);
    }

    #[Test]
    public function a_subscription_line_carries_a_monthly_count_and_no_total(): void
    {
        $this->actingAs($this->user())
            ->postJson($this->url(), $this->valid([
                'credits' => [
                    ['session_type' => 'gruppe-uuid', 'kind' => 'subscription', 'per_month' => 1, 'count' => 9],
                ],
            ]))
            ->assertRedirect();

        $line = Access::firstWhere('handle', 'choiraccelerator')->credits[0];

        $this->assertSame('subscription', $line['kind']);
        $this->assertSame(1, $line['per_month']);
        $this->assertNull($line['count']);
    }

    #[Test]
    public function a_credit_line_needs_a_session_type_and_a_count(): void
    {
        $this->actingAs($this->user())
            ->postJson($this->url(), $this->valid([
                'credits' => [['kind' => 'one_time']],
            ]))
            ->assertJsonValidationErrors(['credits.0.session_type', 'credits.0.count']);

        $this->assertSame(0, Access::count());
    }

    #[Test]
    public function a_content_kind_nobody_registered_is_refused(): void
    {
        $this->actingAs($this->user())
            ->postJson($this->url(), $this->valid([
                'contents' => [['kind' => 'kursplayer', 'ref' => 'x']],
            ]))
            ->assertJsonValidationErrors('contents.0.kind');

        $this->assertSame(0, Access::count());
    }

    #[Test]
    public function a_kind_already_stored_survives_its_registration_going_away(): void
    {
        // Ein Host meldet `community` ab, etwa bei einem Deploy, der die Zeile
        // vergisst. Die gespeicherten Inhalte duerfen dann nicht das Speichern
        // jeder anderen Aenderung blockieren.
        $access = $this->access(['contents' => [['kind' => 'community', 'ref' => 'space']]]);

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid([
                'name' => 'Neu',
                'contents' => [['kind' => 'community', 'ref' => 'space']],
            ]))
            ->assertRedirect();

        $this->assertSame('Neu', $access->fresh()->name);
    }

    #[Test]
    public function an_access_cannot_contain_itself(): void
    {
        $access = $this->access();

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid([
                'contents' => [['kind' => 'access', 'ref' => 'choiraccelerator']],
            ]))
            ->assertJsonValidationErrors('contents');

        $this->assertSame([], $access->fresh()->contents ?? []);
    }

    #[Test]
    public function a_cycle_through_nested_accesses_is_refused(): void
    {
        $this->access(['handle' => 'a', 'name' => 'A', 'contents' => [['kind' => 'access', 'ref' => 'b']]]);
        $this->access(['handle' => 'b', 'name' => 'B', 'contents' => [['kind' => 'access', 'ref' => 'c']]]);
        $c = $this->access(['handle' => 'c', 'name' => 'C']);

        $this->actingAs($this->user())
            ->patchJson($this->url($c), $this->valid([
                'handle' => 'c', 'name' => 'C',
                'contents' => [['kind' => 'access', 'ref' => 'a']],
            ]))
            ->assertJsonValidationErrors('contents');

        $this->assertSame([], $c->fresh()->contents ?? []);
    }

    #[Test]
    public function nesting_without_a_cycle_is_fine_also_into_a_slug_that_has_no_record_yet(): void
    {
        $this->access(['handle' => 'a', 'name' => 'A', 'contents' => [['kind' => 'access', 'ref' => 'b']]]);
        $b = $this->access(['handle' => 'b', 'name' => 'B']);

        $this->actingAs($this->user())
            ->patchJson($this->url($b), $this->valid([
                'handle' => 'b', 'name' => 'B',
                'contents' => [['kind' => 'access', 'ref' => 'noch-nicht-angelegt']],
            ]))
            ->assertRedirect();

        $this->assertSame('noch-nicht-angelegt', $b->fresh()->contents[0]['ref']);
    }

    #[Test]
    public function the_handle_stays_editable_while_nothing_carries_it(): void
    {
        // Ohne `entitlements` gibt es keine Vergabe, also nichts einzufrieren.
        $access = $this->access();

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['handle' => 'neu']))
            ->assertRedirect();

        $this->assertSame('neu', $access->fresh()->handle);
    }

    #[Test]
    public function the_handle_freezes_once_a_grant_carries_it(): void
    {
        $this->entitlementsTable();
        $access = $this->access();
        $this->grant('choiraccelerator');

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['handle' => 'umbenannt']))
            ->assertJsonValidationErrors('handle');

        $this->assertSame('choiraccelerator', $access->fresh()->handle);

        // Alles andere bleibt aenderbar.
        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['name' => 'Accelerator neu']))
            ->assertRedirect();

        $this->assertSame('Accelerator neu', $access->fresh()->name);
    }

    #[Test]
    public function a_grant_for_another_slug_freezes_nothing(): void
    {
        $this->entitlementsTable();
        $access = $this->access();
        $this->grant('etwas-anderes');

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['handle' => 'neu']))
            ->assertRedirect();

        $this->assertSame('neu', $access->fresh()->handle);
    }

    #[Test]
    public function a_granted_access_cannot_be_deleted(): void
    {
        $this->entitlementsTable();
        $access = $this->access();
        $this->grant('choiraccelerator');

        $this->actingAs($this->user())
            ->deleteJson($this->url($access))
            ->assertJsonValidationErrors('handle');

        $this->assertSame(1, Access::count());
    }

    #[Test]
    public function an_access_nobody_holds_can_be_deleted(): void
    {
        $access = $this->access();

        $this->actingAs($this->user())
            ->delete($this->url($access))
            ->assertRedirect(cp_route('utilities.product-accesses'));

        $this->assertSame(0, Access::count());
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

    /**
     * Die Zeilen, wie das Formular sie zurueckschickt: mit ihrer Nummer.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sentBack(Access $access): array
    {
        return array_map(fn (array $line) => array_merge($line, ['ended' => ($line['ended_at'] ?? null) !== null]), $access->fresh()->credits);
    }

    #[Test]
    public function before_a_grant_a_credit_line_can_be_removed(): void
    {
        $access = $this->access(['credits' => $this->twoLines()]);

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['credits' => [$this->sentBack($access)[0]]]))
            ->assertRedirect();

        $this->assertSame([0], array_column($access->fresh()->credits, 'line'));
    }

    #[Test]
    public function after_a_grant_a_credit_line_cannot_be_removed(): void
    {
        $this->entitlementsTable();
        $access = $this->access(['credits' => $this->twoLines()]);
        $this->grant('choiraccelerator');

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['credits' => [$this->sentBack($access)[0]]]))
            ->assertJsonValidationErrors('credits');

        $this->assertSame([0, 1], array_column($access->fresh()->credits, 'line'));
    }

    #[Test]
    public function after_a_grant_a_credit_line_can_be_ended_and_its_count_changed(): void
    {
        $this->entitlementsTable();
        $access = $this->access(['credits' => $this->twoLines()]);
        $this->grant('choiraccelerator');

        $lines = $this->sentBack($access);
        $lines[0]['count'] = 3;
        $lines[1]['ended'] = true;

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['credits' => $lines]))
            ->assertRedirect();

        $credits = $access->fresh()->credits;
        $this->assertSame(3, $credits[0]['count']);
        $this->assertNull($credits[0]['ended_at']);
        $this->assertNotNull($credits[1]['ended_at']);
        $this->assertSame([0, 1], array_column($credits, 'line'));
    }

    #[Test]
    public function a_line_number_is_never_handed_out_twice(): void
    {
        $access = $this->access(['credits' => $this->twoLines()]);

        // Zeile 1 weg, solange es noch geht ...
        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['credits' => [$this->sentBack($access)[0]]]))
            ->assertRedirect();

        // ... und eine neue dazu. Sie bekommt 2, nicht wieder 1: ein Schluessel
        // `…:1` koennte schon unterwegs sein, und er meinte die alte Zeile.
        $lines = $this->sentBack($access);
        $lines[] = ['session_type' => 'neu', 'kind' => 'one_time', 'count' => 1];

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['credits' => $lines]))
            ->assertRedirect();

        $this->assertSame([0, 2], array_column($access->fresh()->credits, 'line'));
    }

    #[Test]
    public function the_form_cannot_invent_a_line_number(): void
    {
        $access = $this->access(['credits' => $this->twoLines()]);

        $lines = $this->sentBack($access);
        $lines[] = ['line' => 7, 'session_type' => 'erfunden', 'kind' => 'one_time', 'count' => 1];

        $this->actingAs($this->user())
            ->patchJson($this->url($access), $this->valid(['credits' => $lines]))
            ->assertJsonValidationErrors('credits.2.line');

        $this->assertSame([0, 1], array_column($access->fresh()->credits, 'line'));
    }

    #[Test]
    public function an_import_can_set_the_line_numbers_it_already_uses(): void
    {
        // Die Uebernahme (Z3) setzt `line` = alter Index. Die naechste neue Zeile
        // kommt danach, nicht dazwischen.
        $access = Access::create($this->valid([
            'credits' => [
                ['line' => 0, 'session_type' => 'einzel', 'kind' => 'one_time', 'count' => 2],
                ['line' => 1, 'session_type' => 'gruppe', 'kind' => 'one_time', 'count' => 6],
            ],
        ]));

        $credits = $access->credits;
        $credits[] = ['session_type' => 'neu', 'kind' => 'one_time', 'count' => 1];
        $access->update(['credits' => $credits]);

        $this->assertSame([0, 1, 2], array_column($access->fresh()->credits, 'line'));
    }

    #[Test]
    public function a_user_without_the_permission_cannot_write(): void
    {
        $access = $this->access();
        $user = $this->userWithoutPermission();

        $this->actingAs($user)->postJson($this->url(), $this->valid(['handle' => 'neu']))->assertForbidden();
        $this->actingAs($user)->patchJson($this->url($access), $this->valid(['name' => 'X']))->assertForbidden();
        $this->actingAs($user)->deleteJson($this->url($access))->assertForbidden();
        $this->actingAs($user)->getJson($this->url($access))->assertForbidden();
        $this->actingAs($user)->getJson($this->url().'/new')->assertForbidden();

        $this->assertSame('ChoirAccelerator', $access->fresh()->name);
        $this->assertSame(1, Access::count());
    }

    #[Test]
    public function the_pages_render_with_their_urls(): void
    {
        $access = $this->access(['contents' => [['kind' => 'access', 'ref' => 'fehlt']]]);

        $this->actingAs($this->user())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('statamic-products::Accesses/Index'));

        $this->actingAs($this->user())
            ->get($this->url().'/new')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-products::Accesses/Create')
                ->has('form.kinds'));

        $this->actingAs($this->user())
            ->get($this->url($access))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-products::Accesses/Show')
                ->where('access.values.handle', 'choiraccelerator')
                ->where('access.targets.access|fehlt.state', 'missing'));
    }

    #[Test]
    public function the_listing_serves_rows_and_columns(): void
    {
        $this->access(['credits' => $this->twoLines(), 'contents' => [['kind' => 'access', 'ref' => 'fehlt']]]);

        $this->actingAs($this->user())
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.handle', 'choiraccelerator')
            ->assertJsonPath('data.0.contents_count', 1)
            ->assertJsonPath('data.0.credits_count', 2)
            ->assertJsonPath('data.0.contents_missing', true)
            ->assertJsonStructure(['meta' => ['columns']]);
    }

    #[Test]
    public function the_create_route_does_not_end_in_create(): void
    {
        // Core haengt jede URL auf `/create` unter „Hilfsmittel"; siehe Produkte.
        $this->assertStringEndsWith('/product-accesses/new', cp_route('utilities.product-accesses.create'));
    }
}
