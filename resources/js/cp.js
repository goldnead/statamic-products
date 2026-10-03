/**
 * Control Panel entry. The registered name must match what the controller
 * passes to `Inertia::render()`, exactly.
 */

import ProductsIndex from './pages/Products/Index.vue';
import ProductsCreate from './pages/Products/Create.vue';
import ProductsShow from './pages/Products/Show.vue';
import AccessesIndex from './pages/Accesses/Index.vue';
import AccessesCreate from './pages/Accesses/Create.vue';
import AccessesShow from './pages/Accesses/Show.vue';
import SetupRequired from './pages/SetupRequired.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-products::Accesses/Index', AccessesIndex);
    Statamic.$inertia.register('statamic-products::Accesses/Create', AccessesCreate);
    Statamic.$inertia.register('statamic-products::Accesses/Show', AccessesShow);
    Statamic.$inertia.register('statamic-products::Products/Index', ProductsIndex);
    Statamic.$inertia.register('statamic-products::Products/Create', ProductsCreate);
    Statamic.$inertia.register('statamic-products::Products/Show', ProductsShow);
    Statamic.$inertia.register('statamic-products::SetupRequired', SetupRequired);
});
