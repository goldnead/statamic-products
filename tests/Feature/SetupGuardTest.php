<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * The screen hangs off `Utility::register()`, so its nav entry appears as soon
 * as composer has put the package there — while the migrations are still a
 * separate, manual step. In that window `/cp/utilities/products` answered HTTP
 * 500. These tests reproduce that database — everything present except this
 * addon's own table — and hold the page to an empty state plus a line in the
 * log.
 */
class SetupGuardTest extends TestCase
{
    private const TABLE = 'products';

    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('setup@example.com')->makeSuper())->save();
    }

    /**
     * The un-migrated database, without losing it for good.
     *
     * `loadMigrationsFrom()` rolls the addon's migrations back when the
     * application is torn down, and a `down()` that alters a table somebody
     * dropped fails — the run would end in errors on top of green assertions.
     * Renaming is what the guard actually asks about (`Schema::hasTable()` says
     * no), and `tearDown()` puts it back.
     */
    private function dropAddonTables(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            Schema::rename(self::TABLE, 'hidden_'.self::TABLE);
        }
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('hidden_'.self::TABLE)) {
            Schema::rename('hidden_'.self::TABLE, self::TABLE);
        }

        parent::tearDown();
    }

    #[Test]
    public function the_index_answers_200_when_its_table_is_missing(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->user())
            ->get('/cp/utilities/products')
            ->assertOk();
    }

    #[Test]
    public function the_index_renders_the_setup_screen_and_names_the_missing_table(): void
    {
        $this->dropAddonTables();

        $page = $this->actingAs($this->user())
            ->get('/cp/utilities/products')
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-products::SetupRequired', $page['component']);
        $this->assertContains('products', $page['props']['tables']);
        $this->assertNotEmpty($page['props']['heading']);
        $this->assertNotEmpty($page['props']['description']);
    }

    /**
     * The point of the guard is a readable page, not a quiet one. If this test
     * ever goes red the addon has traded a visible 500 for a silent nothing.
     */
    #[Test]
    public function the_reason_reaches_the_log(): void
    {
        $this->dropAddonTables();

        Log::spy();

        $this->actingAs($this->user())
            ->get('/cp/utilities/products')
            ->assertOk();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'statamic-products')
                && str_contains($message, 'php artisan migrate'))
            ->once();
    }

    /**
     * The listing fetches its rows over XHR against the same action. Guarding
     * only the Inertia branch would leave that request answering 500 behind a
     * page that looked fine.
     */
    #[Test]
    public function the_listing_xhr_is_guarded_too(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->user())
            ->getJson('/cp/utilities/products')
            ->assertOk();
    }

    #[Test]
    public function a_migrated_install_still_renders_the_listing(): void
    {
        $page = $this->actingAs($this->user())
            ->get('/cp/utilities/products')
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-products::Products/Index', $page['component']);
    }
}
