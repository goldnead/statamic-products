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

    // ---- Ordner je Ablage ------------------------------------------------------

    #[Test]
    public function a_container_can_be_narrowed_to_one_folder(): void
    {
        AccessContainers::allow(['assets', 'downloads' => 'verkauf']);

        $this->assertTrue(AccessContainers::allowsAsset('downloads::verkauf/plan.pdf'));
        $this->assertTrue(AccessContainers::allowsAsset('downloads::verkauf/unter/plan.pdf'));
        $this->assertTrue(AccessContainers::allowsAsset('assets::egal/bild.jpg'));
        $this->assertFalse(AccessContainers::allowsAsset('downloads::inbox/privat.pdf'));
        $this->assertFalse(AccessContainers::allowsAsset('downloads::verkaufX/plan.pdf'));
        $this->assertFalse(AccessContainers::allowsAsset('downloads::verkauf/../inbox/privat.pdf'));
        $this->assertFalse(AccessContainers::allowsAsset('downloads::plan.pdf'));
        $this->assertSame('verkauf', AccessContainers::folder('downloads'));
        $this->assertNull(AccessContainers::folder('assets'));
    }

    #[Test]
    public function a_file_outside_the_allowed_folder_is_refused_by_the_server(): void
    {
        AccessContainers::allow(['downloads' => 'verkauf']);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                'contents' => [['kind' => 'file', 'ref' => 'downloads::inbox/privat.pdf']],
            ]))
            ->assertJsonValidationErrors('contents.0.ref');

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                'cover' => 'downloads::community/bild.jpg',
            ]))
            ->assertJsonValidationErrors('cover');

        $this->assertSame(0, Access::count());

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/product-accesses', $this->payload([
                'contents' => [['kind' => 'file', 'ref' => 'downloads::verkauf/plan.pdf']],
            ]))
            ->assertRedirect();

        $this->assertSame('downloads::verkauf/plan.pdf', Access::first()->contents[0]['ref']);
    }

    #[Test]
    public function the_picker_of_a_narrowed_container_starts_in_its_folder_and_keeps_subfolders_visible(): void
    {
        AccessContainers::allow(['assets', 'downloads' => 'verkauf']);

        // Core blendet bei `restrict` die Unterordner aus; liegen die Dateien
        // in einem (`verkauf/baraye/`), bliebe der Waehler leer. Die Grenze
        // zieht der Server (siehe die Tests zu allowsAsset), nicht der Waehler.
        $this->actingAs($this->user())
            ->get('/cp/utilities/product-accesses/new')
            ->assertInertia(fn ($page) => $page
                ->has('form.assetPickers', 2)
                ->where('form.assetPickers.1.handle', 'downloads')
                ->where('form.assetPickers.1.blueprint.tabs.0.sections.0.fields.0.folder', 'verkauf')
                ->where('form.assetPickers.1.blueprint.tabs.0.sections.0.fields.0.restrict', false));
    }

    #[Test]
    public function a_stored_file_outside_the_folder_stays_savable(): void
    {
        $access = Access::create($this->payload([
            'contents' => [['kind' => 'file', 'ref' => 'downloads::inbox/alt.pdf']],
        ]));

        AccessContainers::allow(['downloads' => 'verkauf']);

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/product-accesses/'.$access->id, $this->payload([
                'name' => 'Neu',
                'contents' => [['kind' => 'file', 'ref' => 'downloads::inbox/alt.pdf']],
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
