<?php

namespace Goldnead\StatamicProducts\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP listing runs before its first query.
 *
 * The products screen hangs off `Utility::register()`, so its nav entry appears
 * the moment composer put the package there — migrations are a separate, manual
 * step. Between the two, `/cp/utilities/products` answered HTTP 500, because
 * the listing reaches for `products` while the page is being built. A missing
 * table is an operator's unfinished setup, not a bug, and it owes the reader a
 * sentence rather than a stack trace.
 *
 * The reason does not disappear with the 500, though: every guarded page that
 * turns somebody away writes why to the log first. A page that renders an empty
 * state and says nothing anywhere would be worse than the crash it replaced —
 * the site would look installed and never work.
 */
final class Setup
{
    /**
     * `goldnead/statamic-brand-context` 1.27's route middleware for CP detail
     * pages, as a class name, never an import: the package is a suggest, and a
     * `use` of a class that is not installed is a fatal on the first page.
     */
    private const DETAIL_GUARD = 'Goldnead\BrandContext\Http\Middleware\GuardSetup';

    /**
     * Route middleware that shows this addon's setup screen on a detail page
     * whose tables are missing, or nothing when brand-context (>= 1.27) is not
     * installed. Without it the page behaves as it always did.
     *
     * @return list<string>
     */
    public static function detailGuard(string $title, string ...$tables): array
    {
        return self::detailGuardFrom(self::DETAIL_GUARD, $title, ...$tables);
    }

    /**
     * {@see detailGuard()} with the middleware class named, so a test can ask
     * what happens when it is not there. Not meant for anything else.
     *
     * @internal
     *
     * @param  class-string|string  $guard
     * @return list<string>
     */
    public static function detailGuardFrom(string $guard, string $title, string ...$tables): array
    {
        if (! class_exists($guard) || ! method_exists($guard, 'using') || ! defined($guard.'::ALIAS')) {
            return [];
        }

        // The class is in vendor, but the alias is the package's provider's to
        // register. A route naming an alias nobody registered is an HTTP 500
        // (Laravel reads it as a class name), so no alias means no middleware.
        // Routes load after every provider has booted, so the answer is final.
        if (! array_key_exists(constant($guard.'::ALIAS'), app('router')->getMiddleware())) {
            return [];
        }

        return [$guard::using(self::class, $title, ...$tables)];
    }

    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-products: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('statamic-products::SetupRequired', [
            'title' => $title,
            'heading' => __('statamic-products::messages.setup_required_heading'),
            'description' => __('statamic-products::messages.setup_required_description'),
            'tables' => $missing,
        ]);
    }
}
