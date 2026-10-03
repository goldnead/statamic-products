<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider;
use Goldnead\Entitlements\Contracts\PackageResolver;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\AccessPackageResolver;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Der transitive PackageResolver fuer statamic-entitlements.
 *
 * Die Regel, die hier festgehalten wird: **`active` steuert nur, ob ein Zugang
 * neu vergeben oder angeboten wird, nicht was bestehende Vergaben abdecken.**
 * Ein inaktiver Zugang wird aufgeloest wie ein aktiver, direkt und
 * verschachtelt. Das CP sagt am Schalter: „Bestehende Vergaben gelten weiter."
 * Wer wirklich entziehen will, nimmt `revoke()` in statamic-entitlements.
 */
class AccessResolverTest extends TestCase
{
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

        if (! interface_exists(PackageResolver::class)) {
            $this->markTestSkipped('statamic-entitlements is not installed.');
        }

        // entitlements stempelt jede Vergabe mit einer Marke, also braucht es
        // die Tabellen von brand-context.
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
    }

    protected function member(): SubjectReference
    {
        return new SubjectReference('user', '42');
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

    protected function nested(string $handle): array
    {
        return ['kind' => 'access', 'ref' => $handle];
    }

    /** Ein Kurs in statamic-courses, gefragt wird nach seinem Slug. */
    protected function course(string $slug, ?string $product = null): string
    {
        Collection::make('courses')->save();

        $entry = Entry::make()->collection('courses')->slug($slug)->id('course-'.$slug);

        if ($product !== null) {
            $entry->set('product', $product);
        }

        $entry->save();

        return (string) $entry->id();
    }

    /** Drei Ebenen: aussen -> mitte -> innen -> Kurs cvt-101. */
    protected function threeLevels(): void
    {
        $id = $this->course('cvt-101');

        $this->access('innen', [['kind' => 'course', 'ref' => $id]]);
        $this->access('mitte', [$this->nested('innen')]);
        $this->access('aussen', [$this->nested('mitte')]);
    }

    #[Test]
    public function a_grant_covers_a_course_nested_two_levels_deep(): void
    {
        $this->threeLevels();

        Entitlements::grant($this->member(), 'aussen', 'manual');

        $this->assertTrue(Entitlements::allows($this->member(), 'cvt-101'));
        $this->assertTrue(Entitlements::allows($this->member(), 'innen'));
        $this->assertFalse(Entitlements::allows($this->member(), 'cvt-102'));
    }

    #[Test]
    public function deactivating_the_inner_access_keeps_existing_grants(): void
    {
        $this->threeLevels();

        Entitlements::grant($this->member(), 'aussen', 'manual');
        $this->assertTrue(Entitlements::allows($this->member(), 'cvt-101'));

        Access::query()->where('handle', 'innen')->firstOrFail()->update(['active' => false]);

        $this->assertTrue(Entitlements::allows($this->member(), 'cvt-101'));
        $this->assertTrue(Entitlements::allows($this->member(), 'innen'));
    }

    #[Test]
    public function an_inactive_outer_access_still_covers_its_contents(): void
    {
        // Ausgemustert, nicht entzogen: wer es gekauft hat, behaelt es.
        $this->threeLevels();
        Access::query()->where('handle', 'aussen')->firstOrFail()->update(['active' => false]);

        Entitlements::grant($this->member(), 'aussen', 'manual');

        $this->assertTrue(Entitlements::allows($this->member(), 'cvt-101'));
        $this->assertTrue(Entitlements::allows($this->member(), 'mitte'));
    }

    #[Test]
    public function a_change_in_the_same_request_is_seen_by_the_next_check(): void
    {
        $this->threeLevels();

        Entitlements::grant($this->member(), 'aussen', 'manual');
        $this->assertTrue(Entitlements::allows($this->member(), 'cvt-101'));

        // Das Modell wirft das Gemerkte beim Speichern weg.
        Access::query()->where('handle', 'mitte')->firstOrFail()->update(['contents' => []]);

        $this->assertFalse(Entitlements::allows($this->member(), 'cvt-101'));
        $this->assertFalse(Entitlements::allows($this->member(), 'innen'));
    }

    #[Test]
    public function a_course_is_also_found_by_its_product_slug(): void
    {
        // statamic-courses fragt nach `product`, wenn gesetzt, sonst nach dem Slug.
        $id = $this->course('stimme-im-chor', 'cvt-201');
        $this->access('paket', [['kind' => 'course', 'ref' => $id]]);

        Entitlements::grant($this->member(), 'paket', 'manual');

        $this->assertTrue(Entitlements::allows($this->member(), 'cvt-201'));
        $this->assertTrue(Entitlements::allows($this->member(), $id));
        $this->assertFalse(Entitlements::allows($this->member(), 'stimme-im-chor'));
    }

    #[Test]
    public function other_kinds_match_by_their_ref(): void
    {
        $this->access('paket', [
            ['kind' => 'community', 'ref' => 'chorleitung'],
            ['kind' => 'file', 'ref' => 'downloads::noten.pdf'],
        ]);

        Entitlements::grant($this->member(), 'paket', 'manual');

        $this->assertTrue(Entitlements::allows($this->member(), 'chorleitung'));
        $this->assertTrue(Entitlements::allows($this->member(), 'downloads::noten.pdf'));
    }

    #[Test]
    public function a_cycle_in_stored_data_ends_instead_of_looping(): void
    {
        // Das Formular verweigert Kreise; ein Import am Modell vorbei nicht.
        $this->access('a', [$this->nested('b'), ['kind' => 'community', 'ref' => 'raum-a']]);
        $this->access('b', [$this->nested('a'), ['kind' => 'community', 'ref' => 'raum-b']]);

        Entitlements::grant($this->member(), 'a', 'manual');

        $this->assertTrue(Entitlements::allows($this->member(), 'raum-b'));
        $this->assertSame(['b'], app(PackageResolver::class)->packagesContaining('a'));
        $this->assertEqualsCanonicalizing(['a', 'b'], app(PackageResolver::class)->packagesContaining('raum-a'));
    }

    #[Test]
    public function the_answer_never_contains_the_slug_asked_about(): void
    {
        $this->access('a', [$this->nested('a')]);

        $this->assertSame([], app(PackageResolver::class)->packagesContaining('a'));
    }

    #[Test]
    public function brands_do_not_split_the_graph(): void
    {
        // Eine Vergabe kennt keine Marke, Slugs sind ueber alle Marken eindeutig.
        $this->access('innen', [['kind' => 'community', 'ref' => 'raum']], ['brand_id' => 2]);
        $this->access('aussen', [$this->nested('innen')], ['brand_id' => 1]);

        $this->assertEqualsCanonicalizing(['innen', 'aussen'], app(PackageResolver::class)->packagesContaining('raum'));
    }

    #[Test]
    public function all_accesses_are_read_once_per_request(): void
    {
        $this->threeLevels();
        Entitlements::grant($this->member(), 'aussen', 'manual');

        DB::enableQueryLog();

        foreach (['cvt-101', 'innen', 'mitte', 'cvt-102', 'cvt-101'] as $slug) {
            Entitlements::allows($this->member(), $slug);
        }

        $reads = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'product_accesses'));

        $this->assertCount(1, $reads);
    }

    #[Test]
    public function it_binds_over_the_null_resolver(): void
    {
        $this->assertInstanceOf(AccessPackageResolver::class, app(PackageResolver::class));
    }

    #[Test]
    public function a_resolver_the_site_binds_wins(): void
    {
        $this->threeLevels();

        $site = new class implements PackageResolver
        {
            public function packagesContaining(string $productSlug): array
            {
                return [];
            }
        };

        // Wie adriangoldner.com: `bind` im register() der Website.
        $this->app->bind(PackageResolver::class, fn () => $site);

        Entitlements::grant($this->member(), 'aussen', 'manual');

        $this->assertSame($site, app(PackageResolver::class));
        $this->assertSame([], app(PackageResolver::class)->packagesContaining('cvt-101'));
    }

    #[Test]
    public function before_the_migration_it_answers_nothing_instead_of_failing(): void
    {
        Schema::drop('product_accesses');

        $this->assertSame([], app(PackageResolver::class)->packagesContaining('cvt-101'));
    }
}
