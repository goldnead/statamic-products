<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicProducts\Integrations\Map\ProductMapObjects;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The dry run of a product: which event starts it (paths) and what it would do
 * with a purchase (react). Nothing is charged, invoiced, sent, queued or granted,
 * and the test holds the line on that.
 *
 * Needs the simulation classes of statamic-brand-context; skipped without them.
 */
class ProductSimulationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Effect::class)) {
            $this->markTestSkipped('needs the simulation classes of statamic-brand-context (Effect, SimEvent, SimPerson)');
        }
    }

    protected function produkt(string $handle, string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'handle' => $handle,
            'name' => $name,
            'type' => Product::TYPE_ACCESS,
            'amount_cent' => 2900,
            'digital' => true,
            'active' => true,
        ], $overrides));
    }

    protected function provider(): ProductMapObjects
    {
        return new ProductMapObjects;
    }

    protected function at(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-21 10:00:00');
    }

    /**
     * The effects of a straight line, one after the other.
     *
     * @param  list<Effect>  $effects
     * @return list<Effect>
     */
    protected function line(array $effects): array
    {
        $line = [];
        $current = $effects;

        while ($current !== []) {
            $line[] = $current[0];
            $current = $current[0]->children;
        }

        return $line;
    }

    /** @return list<string> */
    protected function kinds(array $effects): array
    {
        return array_map(fn (Effect $effect) => $effect->kind, $this->line($effects));
    }

    protected function react(string $id, ?SimPerson $person = null): array
    {
        return $this->provider()->react($id, new SimEvent(SimEvent::PURCHASED), $person ?? SimPerson::unknown(), $this->at());
    }

    protected function userWith(string ...$permissions)
    {
        static $n = 0;

        foreach (['access payments utility', 'access products utility'] as $permission) {
            Permission::register($permission);
        }
        $role = tap(Role::make('sim-rolle-'.++$n)->addPermission('access cp'))->save();
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
        $role->save();

        return tap(User::make()->email("sim{$n}@example.com")->assignRole($role))->save();
    }

    #[Test]
    public function paths_a_product_that_exists_begins_with_its_purchase(): void
    {
        $this->produkt('atemkurs', 'Atemkurs');

        $paths = $this->provider()->paths('atemkurs');

        $this->assertCount(1, $paths);
        $this->assertInstanceOf(SimEvent::class, $paths[0]);
        $this->assertSame(SimEvent::PURCHASED, $paths[0]->type);
    }

    #[Test]
    public function paths_a_product_that_does_not_exist_begins_nowhere(): void
    {
        $this->assertSame([], $this->provider()->paths('gibt-es-nicht'));
    }

    #[Test]
    public function react_a_product_with_two_grants_takes_the_straight_line_and_emits_the_grant(): void
    {
        $this->produkt('masterclass', 'Masterclass', ['grants' => ['kurs-a', 'bonus']]);

        $effects = $this->react('masterclass');
        $line = $this->line($effects);

        $this->assertSame(
            [Effect::UNKNOWN, Effect::ACCESS, Effect::ACCESS, Effect::EMIT],
            array_map(fn (Effect $e) => $e->kind, $line),
        );

        $this->assertSame(__('statamic-products::messages.sim_checkout'), $line[0]->label);
        $this->assertSame(__('statamic-products::messages.sim_checkout_detail'), $line[0]->detail);

        $this->assertSame(__('statamic-products::messages.sim_access', ['slug' => 'kurs-a']), $line[1]->label);
        $this->assertSame(['slug' => 'kurs-a'], $line[1]->meta);
        $this->assertSame(['slug' => 'bonus'], $line[2]->meta);

        $emit = $line[3];
        $this->assertSame(SimEvent::GRANTED, $emit->emits->type);
        $this->assertSame(['triggers', 'unlocks'], $emit->emits->via);
        $this->assertSame(
            ['slugs' => ['kurs-a', 'bonus'], 'product' => 'masterclass'],
            $emit->emits->data,
        );
        $this->assertSame(
            __('statamic-products::messages.sim_granted', ['product' => 'Masterclass']),
            $emit->label,
        );
    }

    #[Test]
    public function react_a_product_without_grants_has_no_access_line_and_no_emit(): void
    {
        $this->produkt('kaffee', 'Kaffee', ['grants' => null]);

        $effects = $this->react('kaffee');

        $this->assertSame([Effect::UNKNOWN], $this->kinds($effects));
        $this->assertSame([], $effects[0]->children);
    }

    #[Test]
    public function react_an_inactive_product_stops_the_path(): void
    {
        $this->produkt('altkurs', 'Alter Kurs', ['active' => false, 'grants' => ['kurs-alt']]);

        $effects = $this->react('altkurs');

        $this->assertSame([Effect::STOP], $this->kinds($effects));
        $this->assertSame(__('statamic-products::messages.sim_inactive'), $effects[0]->label);
    }

    #[Test]
    public function react_a_missing_product_is_unknown_not_guessed(): void
    {
        $effects = $this->react('gibt-es-nicht');

        $this->assertSame([Effect::UNKNOWN], $this->kinds($effects));
        $this->assertSame(__('statamic-products::messages.sim_missing'), $effects[0]->label);
    }

    #[Test]
    public function react_a_known_person_gets_the_same_straight_line(): void
    {
        $this->produkt('masterclass', 'Masterclass', ['grants' => ['kurs-a']]);

        $this->assertSame(
            $this->kinds($this->react('masterclass')),
            $this->kinds($this->react('masterclass', SimPerson::known([], ['kurs-a']))),
        );
    }

    #[Test]
    public function simulation_is_not_authorized_without_a_user(): void
    {
        $this->produkt('atemkurs', 'Atemkurs');

        $this->assertFalse($this->provider()->simulationAuthorized('atemkurs', null));
    }

    #[Test]
    public function simulation_is_not_authorized_for_a_user_without_the_right(): void
    {
        $this->produkt('atemkurs', 'Atemkurs');

        $this->assertFalse($this->provider()->simulationAuthorized('atemkurs', $this->userWith()));
        $this->assertFalse($this->provider()->simulationAuthorized('atemkurs', $this->userWith('access products utility')));
    }

    #[Test]
    public function simulation_is_authorized_for_a_user_with_the_payments_permission(): void
    {
        $this->produkt('atemkurs', 'Atemkurs');

        $this->assertTrue($this->provider()->simulationAuthorized('atemkurs', $this->userWith('access payments utility')));
    }

    #[Test]
    public function the_dry_run_writes_nothing(): void
    {
        // The setup writes (products, roles) first; the fakes and the listener start after it.
        $this->produkt('mit-zugang', 'Mit Zugang', ['grants' => ['kurs-a', 'bonus']]);
        $this->produkt('ohne-zugang', 'Ohne Zugang', ['grants' => null]);
        $this->produkt('aus', 'Aus', ['active' => false, 'grants' => ['kurs-aus']]);
        $user = $this->userWith('access payments utility');

        Mail::fake();
        Queue::fake();

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $map = $this->provider();

        foreach (['mit-zugang', 'ohne-zugang', 'aus', 'gibt-es-nicht'] as $id) {
            $map->paths($id);
            $map->simulationAuthorized($id, $user);

            foreach ([SimPerson::unknown(), SimPerson::known([], [$id]), SimPerson::known()] as $person) {
                $map->react($id, new SimEvent(SimEvent::PURCHASED), $person, $this->at());
            }
        }

        $writes = array_values(array_filter(
            $statements,
            fn (string $sql) => ! preg_match('/^\s*(select|pragma)\b/i', $sql),
        ));

        // The listener must have seen the reads, or the empty list proves nothing.
        $this->assertNotSame([], $statements);
        $this->assertSame([], $writes);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }
}
