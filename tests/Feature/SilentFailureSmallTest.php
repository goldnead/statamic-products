<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicProducts\Http\Controllers\Cp\AccessesController;
use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\ServiceProvider;
use Goldnead\StatamicProducts\Support\AccessGraph;
use Goldnead\StatamicProducts\Support\GrantableAccesses;
use Goldnead\StatamicProducts\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Statamic\Facades\User;

/**
 * Kleine stille Fehler: Log-Luecken und ein Rhythmus, den niemand prueft.
 *
 * 12. Die Event-Auswahl meldet Fehler und die Kappung bei 500 im Log.
 * 13. Markennamen: ein Fehler beim Lesen landet im Log, nicht im Nichts.
 * 14. Der Katalog-Schutz loggt die Exception selbst, nicht nur ihre Meldung.
 * 15. Ein Zugang auf einen geloeschten Kurs wird geloggt.
 */
class SilentFailureSmallTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'handle' => 'abo',
            'name' => 'Abo',
            'type' => Product::TYPE_DOWNLOAD,
            'amount_cent' => 1000,
            'digital' => true,
        ], $overrides);
    }

    protected function abo(): Product
    {
        return Product::create($this->form([
            'interval' => '1 month',
            'times' => 12,
            'currency' => 'CHF',
            'active' => true,
        ]));
    }

    #[Test]
    public function a_failing_event_list_is_logged_not_silently_dropped(): void
    {
        // Die Pruefung der Tabelle wirft, das liegt im selben try. (Eine fehlende
        // Spalte taugt nicht: SQLite liest eine unbekannte Spalte in doppelten
        // Anfuehrungszeichen als Text und wirft nicht. DB selbst zu mocken
        // bricht jeden anderen Verbindungsaufruf.)
        Schema::shouldReceive('hasTable')->with('events')->andThrow(new \RuntimeException('connection lost'));

        Log::spy();

        try {
            $choices = $this->invokeEventChoices();
        } finally {
            // Die Schema-Fassade gibt der Test-Aufbau danach wieder frei (Tabellen aufraeumen).
            Schema::swap(DB::connection()->getSchemaBuilder());
        }

        $this->assertNull($choices);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c = []) => ($c['exception'] ?? null) instanceof \Throwable)
            ->once();
    }

    #[Test]
    public function an_event_list_of_exactly_500_is_not_reported_as_capped(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('title');
        });

        DB::table('events')->insert(array_map(fn (int $i) => [
            'uuid' => sprintf('00000000-0000-0000-0000-%012d', $i),
            'title' => 'Termin '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        ], range(1, 500)));

        Log::spy();

        $choices = $this->invokeEventChoices();

        $this->assertCount(500, $choices);
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_capped_event_list_says_so_in_the_log(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('title');
        });

        DB::table('events')->insert(array_map(fn (int $i) => [
            'uuid' => sprintf('00000000-0000-0000-0000-%012d', $i),
            'title' => 'Termin '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        ], range(1, 501)));

        Log::spy();

        $choices = $this->invokeEventChoices();

        $this->assertCount(500, $choices);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, 'capped at 500'))
            ->once();
    }

    #[Test]
    public function a_failing_brand_lookup_is_logged(): void
    {
        $this->app->instance('brand-context', new class
        {
            public function multiBrandEnabled(): bool
            {
                return true;
            }

            public function hasCurrent(): bool
            {
                return false;
            }

            public function currentId(): ?int
            {
                return null;
            }
        });

        // Ohne die Tabelle wirft die Markenabfrage; die Liste bleibt ohne Namen.
        Schema::dropIfExists('brands');

        Log::spy();

        $entries = (new GrantableAccesses)->grantableProducts();

        $this->assertIsArray($entries);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'brand'))
            ->once();
    }

    #[Test]
    public function the_catalogue_guard_logs_the_exception_itself(): void
    {
        Log::spy();

        $method = new ReflectionMethod(ServiceProvider::class, 'safely');

        $result = $method->invoke(new ServiceProvider($this->app), 'test a thing', fn () => throw new \RuntimeException('boom'));

        $this->assertNull($result);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $context['exception'] instanceof \Throwable)
            ->once();
    }

    #[Test]
    public function an_access_pointing_at_a_deleted_course_is_logged(): void
    {
        Access::create([
            'handle' => 'kurs-weg',
            'name' => 'Kurs weg',
            'contents' => [['kind' => 'course', 'ref' => 'geloescht-42', 'label' => null]],
        ]);

        Log::spy();

        new AccessGraph;

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => ($context['ref'] ?? null) === 'geloescht-42')
            ->once();
    }

    /** @return array<int, array{value: string, label: string}>|null */
    protected function invokeEventChoices(): ?array
    {
        $method = new ReflectionMethod(AccessesController::class, 'eventChoices');

        return $method->invoke(app(AccessesController::class));
    }
}
