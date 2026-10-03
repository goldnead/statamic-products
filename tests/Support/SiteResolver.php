<?php

namespace Goldnead\StatamicProducts\Tests\Support;

use Goldnead\Entitlements\Contracts\PackageResolver;

/** Kennt keine Buendel. */
class SiteResolver implements PackageResolver
{
    /** @return list<string> */
    public function packagesContaining(string $productSlug): array
    {
        return [];
    }
}
