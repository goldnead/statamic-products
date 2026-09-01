/**
 * Control Panel entry. The registered name must match what the controller
 * passes to `Inertia::render()`, exactly.
 */

import ProductsIndex from './pages/Products/Index.vue';
import ProductsShow from './pages/Products/Show.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-products::Products/Index', ProductsIndex);
    Statamic.$inertia.register('statamic-products::Products/Show', ProductsShow);
});
