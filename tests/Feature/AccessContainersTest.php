<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\AccessContainers;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;

/**
 * Aus welchen Ablagen ein Zugang Dateien nehmen darf.
 *
 * Ein Zugang wird verkauft. Eine Datei aus dem Klientenraum einer anderen
 * Marke oder eines Kunden (eine private Probenaufnahme) darf darin nicht
 * landen, auch nicht aus Versehen im Dropdown. Die Website meldet die
 * erlaubten Ablagen an; ohne Anmeldung fallen die Klientenraeume heraus.
 */
class AccessContainersTest extends TestCase
{
    protected $superuser = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['assets', 'downloads', 'clientrooms', 'clientrooms-2'] as $handle) {
            AssetContainer::make($handle)->title(ucfirst($handle))->disk('local')->save();
        }
    }

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge(['handle' => 'accelerator', 'name' => 'Accelerator'], $overrides);
    }

    #[Test]
    public function a_file_from_a_container_the_site_did_not_allow_is_refused(): void
    {
        AccessContainers::allow(['assets', 'downloads']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                'contents' => [['kind' => 'file', 'ref' => 'clientrooms::probe/aufnahme.mp3']],
            ]))
            ->assertJsonValidationErrors('contents.0.ref');

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                'cover' => 'clientrooms-2::bild.jpg',
            ]))
            ->assertJsonValidationErrors('cover');

        $this->assertSame(0, Access::count());
    }

    #[Test]
    public function a_file_from_an_allowed_container_is_fine(): void
    {
        AccessContainers::allow(['assets']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                'cover' => 'assets::cover.jpg',
                'contents' => [['kind' => 'file', 'ref' => 'assets::workbook.pdf']],
            ]))
            ->assertRedirect();

        $this->assertSame('assets::workbook.pdf', Access::first()->contents[0]['ref']);
    }

    #[Test]
    public function without_a_registration_client_room_containers_are_left_out(): void
    {
        config(['statamic-clientrooms.container' => 'clientrooms']);

        $this->assertSame(['assets', 'downloads'], AccessContainers::allowed());

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                'contents' => [['kind' => 'file', 'ref' => 'clientrooms-2::privat.mp3']],
            ]))
            ->assertJsonValidationErrors('contents.0.ref');
    }

    #[Test]
    public function without_clientrooms_and_without_a_registration_every_container_is_offered(): void
    {
        $this->assertSame(['assets', 'clientrooms', 'clientrooms-2', 'downloads'], AccessContainers::allowed());
    }

    #[Test]
    public function the_form_only_offers_allowed_containers(): void
    {
        AccessContainers::allow(['downloads']);

        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/new')
            ->assertInertia(fn ($page) => $page
                ->has('form.assetPickers', 1)
                ->where('form.assetPickers.0.handle', 'downloads'));
    }

    #[Test]
    public function a_stored_file_from_a_container_no_longer_allowed_stays_savable(): void
    {
        $access = Access::create($this->payload([
            'cover' => 'clientrooms::alt.jpg',
            'contents' => [['kind' => 'file', 'ref' => 'clientrooms::alt.pdf']],
        ]));

        AccessContainers::allow(['assets']);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, $this->payload([
                'name' => 'Neu',
                'cover' => 'clientrooms::alt.jpg',
                'contents' => [['kind' => 'file', 'ref' => 'clientrooms::alt.pdf']],
            ]))
            ->assertRedirect();

        $this->assertSame('Neu', $access->fresh()->name);
    }

    // ---- aus dem Code-Review ---------------------------------------------------

    #[Test]
    public function the_model_refuses_deleting_a_granted_access(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('product_slug', 191);
        });

        $access = Access::create($this->payload());
        DB::table('entitlements')->insert(['product_slug' => 'accelerator']);

        try {
            $access->delete();
            $this->fail('Ein vergebener Zugang wurde geloescht.');
        } catch (ValidationException) {
            // erwartet
        }

        $this->assertSame(1, Access::count());
    }

    #[Test]
    public function a_save_without_a_version_token_is_accepted_on_purpose(): void
    {
        // Kein Token heisst: ein Aufrufer, der den Fingerabdruck nicht kennt
        // (ein Skript, ein aelteres Formular). Der wird nicht abgewiesen; der
        // Schutz gilt fuer das Formular dieses Addons, das ihn immer schickt.
        $access = Access::create($this->payload());

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, $this->payload(['name' => 'Ohne Token']))
            ->assertRedirect();

        $this->assertSame('Ohne Token', $access->fresh()->name);
    }
}
