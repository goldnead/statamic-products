<?php

namespace Goldnead\StatamicProducts;

use Goldnead\StatamicPayments\Support\Brands;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicProducts\Http\Controllers\Cp\ProductsController;
use Goldnead\StatamicProducts\Models\Product;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-products';

    /**
     * @var array<string, mixed>
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    ];

    /**
     * The parent boots config off the addon directory, which is resolved
     * through the manifest and comes up empty in package test suites.
     */
    protected $config = false;

    public function register()
    {
        parent::register();

        // Registered here rather than in `bootAddon()`, which only runs when
        // the addon is discovered through the manifest. Without it a product
        // resolves to nothing and simply cannot be bought — a failure that
        // looks like a missing product rather than a missing registration.
        // `statamic-offers` learned this the same way.
        $this->bootCatalogue();
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-products');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->bootUtilities();

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-products-migrations');
    }

    /**
     * Teach the payment catalogue about products.
     *
     * **Two seams, and they answer different questions.**
     *
     * `extend()` is asked "what does this one handle cost". It is reached by
     * anything a browser sends, and by a provider webhook hours after the sale,
     * so it is a single indexed lookup and it does not care which brand is
     * current — a webhook has no brand, and a price that could not be resolved
     * without one would be a price that could not be resolved at all.
     *
     * `contribute()` is asked "what is there". That question only ever comes
     * from a screen, so the answer is scoped: this brand's products, the active
     * ones. It exists because a resolver is only ever handed one handle and can
     * therefore never fill a picker — the reason the product select in the
     * offer form showed three of six products and then refused the save.
     */
    protected function bootCatalogue(): self
    {
        Catalogue::extend(function (string $handle): ?array {
            // Cheap and early. `find()` is called once per row of a listing and
            // several times per invoice, and most of those handles are not ours.
            if ($handle === '') {
                return null;
            }

            return $this->safely('resolve a product', fn () => Product::query()
                ->where('handle', $handle)
                ->where('active', true)
                ->first()
                ?->toCatalogueEntry());
        });

        Catalogue::contribute(fn (): array => $this->safely('list products', function (): array {
            $query = Product::query()->where('active', true);

            // Fail-closed, and it is the right failure for a picker: in
            // multi-brand mode with no brand current, an unscoped list would
            // offer another tenant's catalogue for sale.
            $products = Brands::only($query, Brands::readerId())
                ->orderBy('name')
                ->get();

            return $products
                ->mapWithKeys(fn (Product $product) => [$product->handle => $product->toCatalogueEntry()])
                ->all();
        }) ?? []);

        return $this;
    }

    /**
     * Run a catalogue callback without letting it take the site down.
     *
     * Both seams run inside a checkout and inside the Control Panel. Before the
     * addon's migration has run — the window between `composer require` and
     * `php artisan migrate`, which on a real host is minutes and on a forgotten
     * staging box is months — every query here throws "no such table:
     * products", and an uncaught throw at this point is a 500 on the checkout
     * of a site that was selling fine an hour earlier.
     *
     * **Logged, never swallowed quietly.** An empty catalogue and a broken one
     * look identical from the outside, and "nothing to sell" is the failure
     * shape this family keeps rediscovering. The log line is the number beside
     * the empty state that says which of the two it is.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn|null
     */
    protected function safely(string $what, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::error("statamic-products: could not {$what}; the catalogue is answering as if this addon were not installed.", [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function bootUtilities(): self
    {
        // Inside `Utility::extend`, not straight in boot: `__()` during boot
        // resolves before core's `Localize` middleware has set the user's
        // language, and the nav entry would freeze in the application locale.
        Utility::extend(function () {
            Utility::register('products')
                ->action([ProductsController::class, 'index'])
                ->title(__('statamic-products::messages.utility_title'))
                ->navTitle(__('statamic-products::messages.utility_nav'))
                ->icon('shopping-cart')
                ->description(__('statamic-products::messages.utility_description'))
                ->docsUrl('https://github.com/goldnead/statamic-products#readme')
                ->routes(function ($router) {
                    $router->post('/', [ProductsController::class, 'store'])->name('store');
                    $router->get('{product}', [ProductsController::class, 'show'])->name('show');
                    $router->patch('{product}', [ProductsController::class, 'update'])->name('update');
                    $router->delete('{product}', [ProductsController::class, 'destroy'])->name('destroy');
                });
        });

        return $this;
    }
}
