<?php

namespace Goldnead\StatamicProducts\Tests\Support;

use Goldnead\Entitlements\Contracts\PackageResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Eine Website, die ihren eigenen PackageResolver im register() bindet, wie
 * adriangoldner.com mit `CatalogPackageResolver`.
 */
class SiteResolverProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PackageResolver::class, SiteResolver::class);
    }
}
