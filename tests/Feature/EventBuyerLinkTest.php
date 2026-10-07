<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider;
use Goldnead\Entitlements\Contracts\PackageResolver;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\Accesses;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Der Link fuer Kaeufer an einem Termin (`event`) im Zugang.
 *
 * statamic-events gated nichts: `online_url` am Termin ist oeffentlich. Der
 * Teilnahme-Link eines bezahlten Webinars steht deshalb am Inhalt des Zugangs,
 * und nur wer den Zugang haelt, bekommt ihn zu sehen. Die Regeln:
 *
 * - Die allgemeine Lese-API (`contentItems()`, `contentsOf()`) fuehrt ihn nie.
 * - `Accesses::buyerLinks($slugs)` liefert ihn nur fuer gehaltene Slugs,
 *   verschachtelt und auch bei einem ausgemusterten Zugang.
 * - `Accesses::buyerLinksFor($subject)` fragt statamic-entitlements: ohne
 *   aktive Vergabe nichts, nach einem Entzug nichts.
 */
class EventBuyerLinkTest extends TestCase
{
    private const EVENT = '7f1d1a2e-0000-4000-8000-000000000001';

    private const LINK = 'https://us02web.zoom.us/j/123456789?pwd=geheim';

    protected function getPackageProviders($app)
    {
        $providers = parent::getPackageProviders($app);

        if (interface_exists(PackageResolver::class)) {
            $providers[] = ServiceProvider::class;
            $providers[] = \Goldnead\IdentityContracts\ServiceProvider::class;
            $providers[] = \Goldnead\Entitlements\ServiceProvider::class;
        }

        return $providers;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (interface_exists(PackageResolver::class)) {
            $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
            $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
        }
    }

    protected function access(string $handle, array $contents = [], array $overrides = []): Access
    {
        return Access::query()->create(array_merge([
            'handle' => $handle,
            'name' => ucfirst($handle),
            'active' => true,
            'contents' => $contents,
        ], $overrides));
    }

    protected function webinar(string $handle = 'webinar', array $overrides = []): Access
    {
        return $this->access($handle, [
            ['kind' => 'event', 'ref' => self::EVENT, 'label' => 'Live-Webinar', 'buyer_url' => self::LINK],
        ], $overrides);
    }

    protected $superuser = null;

    protected function superuser()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    // ---- Lesen ---------------------------------------------------------------

    #[Test]
    public function a_held_slug_gets_the_link(): void
    {
        $this->webinar();

        $this->assertSame([[
            'access' => 'webinar',
            'ref' => self::EVENT,
            'label' => 'Live-Webinar',
            'url' => self::LINK,
        ]], Accesses::buyerLinks(['webinar']));
    }

    #[Test]
    public function a_slug_not_held_gets_nothing(): void
    {
        $this->webinar();
        $this->access('anderes', [['kind' => 'course', 'ref' => 'kurs-1']]);

        $this->assertSame([], Accesses::buyerLinks(['anderes']));
        $this->assertSame([], Accesses::buyerLinks([]));
        $this->assertSame([], Accesses::buyerLinks(['gibt-es-nicht', '']));
    }

    #[Test]
    public function the_general_read_api_never_carries_the_link(): void
    {
        $this->webinar();

        $view = Accesses::find('webinar');

        $this->assertSame([['kind' => 'event', 'ref' => self::EVENT, 'label' => 'Live-Webinar']], $view->contentsOf('event'));
        $this->assertSame([['kind' => 'event', 'ref' => self::EVENT, 'label' => 'Live-Webinar']], $view->model()->contentItems());
        $this->assertStringNotContainsString('zoom', json_encode($view->model()->contentItems()));
    }

    #[Test]
    public function a_nested_and_an_inactive_access_still_hand_out_the_link(): void
    {
        $this->webinar('webinar', ['active' => false]);
        $this->access('paket', [['kind' => 'access', 'ref' => 'webinar']]);

        $links = Accesses::buyerLinks(['paket']);

        $this->assertSame([self::LINK], array_column($links, 'url'));
        // Der Zugang, an dem der Link steht, nicht der gehaltene.
        $this->assertSame(['webinar'], array_column($links, 'access'));
    }

    #[Test]
    public function an_event_without_a_link_or_with_an_unsafe_one_is_left_out(): void
    {
        $this->access('ohne', [
            ['kind' => 'event', 'ref' => 'termin-ohne'],
            ['kind' => 'event', 'ref' => 'termin-js', 'buyer_url' => 'javascript:alert(1)'],
            // Ein Link an einer anderen Art zaehlt nicht.
            ['kind' => 'course', 'ref' => 'kurs-1', 'buyer_url' => self::LINK],
        ]);

        $this->assertSame([], Accesses::buyerLinks(['ohne']));
    }

    #[Test]
    public function one_event_is_listed_once_with_the_first_link(): void
    {
        $this->webinar('a');
        $this->access('b', [['kind' => 'event', 'ref' => self::EVENT, 'buyer_url' => 'https://example.com/zweiter']]);

        $this->assertSame([self::LINK], array_column(Accesses::buyerLinks(['a', 'b']), 'url'));
    }

    // ---- Vergaben (statamic-entitlements) -------------------------------------

    #[Test]
    public function with_a_grant_the_subject_gets_the_link(): void
    {
        $this->skipWithoutEntitlements();
        $this->webinar();

        Entitlements::grant(new SubjectReference('user', '42'), 'webinar', 'manual');

        $this->assertSame([self::LINK], array_column(Accesses::buyerLinksFor(new SubjectReference('user', '42')), 'url'));
    }

    #[Test]
    public function without_a_grant_the_subject_gets_nothing(): void
    {
        $this->skipWithoutEntitlements();
        $this->webinar();
        $this->access('anderes', [['kind' => 'course', 'ref' => 'kurs-1']]);

        Entitlements::grant(new SubjectReference('user', '7'), 'anderes', 'manual');
        Entitlements::grant(new SubjectReference('user', '42'), 'webinar', 'manual');

        $this->assertSame([], Accesses::buyerLinksFor(new SubjectReference('user', '7')));
        $this->assertSame([], Accesses::buyerLinksFor(new SubjectReference('user', '99')));
        $this->assertSame([], Accesses::buyerLinksFor(null));
    }

    #[Test]
    public function a_revoked_grant_hands_out_nothing(): void
    {
        $this->skipWithoutEntitlements();
        $this->webinar();

        $grant = Entitlements::grant(new SubjectReference('user', '42'), 'webinar', 'manual');
        Entitlements::revoke($grant, 'test');

        $this->assertSame([], Accesses::buyerLinksFor(new SubjectReference('user', '42')));
    }

    // ---- Control Panel ---------------------------------------------------------

    #[Test]
    public function the_form_saves_the_link_on_an_event_and_shows_it_again(): void
    {
        $this->actingAs($this->superuser())
            ->postJson('/cp/utilities/product-accesses', [
                'handle' => 'webinar',
                'name' => 'Webinar',
                'active' => true,
                'contents' => [
                    ['kind' => 'event', 'ref' => self::EVENT, 'buyer_url' => '  '.self::LINK.'  '],
                    ['kind' => 'access', 'ref' => 'anderes', 'buyer_url' => self::LINK],
                ],
                'credits' => [],
            ])
            ->assertRedirect();

        $access = Access::firstWhere('handle', 'webinar');

        $this->assertSame(self::LINK, $access->contents[0]['buyer_url']);
        // Nur am Termin; an anderen Arten wird er verworfen.
        $this->assertArrayNotHasKey('buyer_url', $access->contents[1]);

        $this->actingAs($this->superuser())
            ->get('/cp/utilities/product-accesses/'.$access->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('access.values.contents.0.buyer_url', self::LINK));
    }

    #[Test]
    public function the_form_refuses_a_link_that_is_not_http(): void
    {
        $this->actingAs($this->superuser())
            ->postJson('/cp/utilities/product-accesses', [
                'handle' => 'webinar',
                'name' => 'Webinar',
                'contents' => [['kind' => 'event', 'ref' => self::EVENT, 'buyer_url' => 'javascript:alert(1)']],
                'credits' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contents.0.buyer_url');

        $this->assertNull(Access::firstWhere('handle', 'webinar'));
    }

    private function skipWithoutEntitlements(): void
    {
        if (! interface_exists(PackageResolver::class)) {
            $this->markTestSkipped('statamic-entitlements is not installed.');
        }
    }
}
