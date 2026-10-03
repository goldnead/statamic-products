<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider;
use Goldnead\Entitlements\Contracts\PackageResolver;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\AccessPackageResolver;
use Goldnead\StatamicProducts\Tests\Support\SiteResolver;
use Goldnead\StatamicProducts\Tests\Support\SiteResolverProvider;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Gegenrichtung zu `AccessResolverTest`: dort registriert products vor
 * entitlements. Hier kommen entitlements und die Website **vor** products,
 * so wie Laravel Paket-Provider vor App-Provider stellt, nur umgekehrt
 * gegenueber products. Der Resolver der Website muss trotzdem gewinnen, und
 * ohne ihn muss der eigene greifen.
 */
class ResolverBindingOrderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        if (! interface_exists(PackageResolver::class)) {
            return parent::getPackageProviders($app);
        }

        $providers = array_values(array_filter(
            parent::getPackageProviders($app),
            fn (string $provider) => $provider !== $this->addonServiceProvider,
        ));

        $providers[] = ServiceProvider::class;
        $providers[] = \Goldnead\IdentityContracts\ServiceProvider::class;
        $providers[] = \Goldnead\Entitlements\ServiceProvider::class;

        if (str_starts_with($this->name(), 'a_site_resolver')) {
            $providers[] = SiteResolverProvider::class;
        }

        $providers[] = $this->addonServiceProvider;

        return $providers;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! interface_exists(PackageResolver::class)) {
            $this->markTestSkipped('statamic-entitlements is not installed.');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
    }

    protected function nestedCommunity(): SubjectReference
    {
        Access::query()->create(['handle' => 'innen', 'name' => 'Innen', 'active' => true, 'contents' => [['kind' => 'community', 'ref' => 'raum']]]);
        Access::query()->create(['handle' => 'aussen', 'name' => 'Aussen', 'active' => true, 'contents' => [['kind' => 'access', 'ref' => 'innen']]]);

        $member = new SubjectReference('user', '7');
        Entitlements::grant($member, 'aussen', 'manual');

        return $member;
    }

    #[Test]
    public function a_site_resolver_registered_before_products_wins(): void
    {
        $member = $this->nestedCommunity();

        $this->assertInstanceOf(SiteResolver::class, app(PackageResolver::class));
        $this->assertFalse(Entitlements::allows($member, 'raum'));
    }

    #[Test]
    public function without_a_site_resolver_products_still_replaces_the_null_one(): void
    {
        $member = $this->nestedCommunity();

        $this->assertInstanceOf(AccessPackageResolver::class, app(PackageResolver::class));
        $this->assertTrue(Entitlements::allows($member, 'raum'));
    }
}
