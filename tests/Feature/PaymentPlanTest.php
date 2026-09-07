<?php

namespace Goldnead\StatamicProducts\Tests\Feature;

use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicProducts\Models\Product;
use Goldnead\StatamicProducts\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein Produkt aus der Tabelle kann einen Zahlungsrhythmus tragen.
 *
 * **Warum das ein eigener Test ist.** `statamic-payments` kann Abo,
 * Ratenzahlung und Testphase seit 1.5.0 — ein Mechanismus, drei Gesichter:
 * `times = null` ist ein Abo, `times = N` eine Ratenzahlung, `trial_days`
 * eine Testphase. Gelesen wird der Plan aus dem Katalog, und solange der eine
 * Config war, stand er dort.
 *
 * Seit dieses Addon die Produkte in eine Tabelle geholt hat, gab
 * `toCatalogueEntry()` nur `handle`, `name`, `amount_cent`, `currency`,
 * `digital` und `grants` weiter. Ein Tabellen-Produkt konnte damit **keinen**
 * Plan tragen: `Subscriptions::planFor()` gab `null` zurueck, ohne Fehler und
 * ohne Log. Die Faehigkeit war nicht kaputt, sie war unerreichbar — und genau
 * solche Luecken findet niemand, weil nichts sie meldet.
 *
 * Der erste Test unten ist deshalb der wichtigste: er haelt fest, dass ein
 * Produkt **ohne** Rhythmus weiterhin keinen bekommt. Eine Migration mit
 * Standardwerten haette aus jedem bestehenden Produkt ein Abo gemacht.
 */
class PaymentPlanTest extends TestCase
{
    protected function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'handle' => 'atemkurs',
            'name' => 'Atemkurs',
            'amount_cent' => 4900,
            'digital' => true,
            'active' => true,
        ], $attributes));
    }

    /** Der Normalfall bleibt der Normalfall. */
    #[Test]
    public function a_product_without_an_interval_has_no_plan(): void
    {
        $this->product();

        $this->assertNull(app(Subscriptions::class)->planFor('atemkurs'));

        // Und der Schluessel taucht gar nicht erst auf — weglassen statt leer
        // schicken, dieselbe Regel wie bei `grants`.
        $this->assertArrayNotHasKey('interval', Product::query()->sole()->toCatalogueEntry());
    }

    /**
     * Der Fall, wegen dem es diese Spalten gibt: eine Ratenzahlung.
     *
     * `times = 3` sind drei Abbuchungen, dann ist Schluss. Das ist die
     * Zusage, die auf der ChoirAccelerator-Seite stand („3 x 520 EUR") und
     * die die eigene Kasse bis zum 07.09.2026 nicht einloesen konnte.
     */
    #[Test]
    public function an_instalment_plan_reaches_the_payment_addon(): void
    {
        $this->product(['interval' => '1 month', 'times' => 3]);

        $plan = app(Subscriptions::class)->planFor('atemkurs');

        $this->assertNotNull($plan, 'Der Plan kam nicht im Zahlungs-Addon an.');
        $this->assertSame('1 month', $plan['interval']);
        $this->assertSame(3, $plan['times']);
    }

    /** Ohne `times` ist derselbe Mechanismus ein Abo. */
    #[Test]
    public function an_interval_without_a_count_is_a_subscription(): void
    {
        $this->product(['interval' => '1 month']);

        $plan = app(Subscriptions::class)->planFor('atemkurs');

        $this->assertNotNull($plan);
        $this->assertNull($plan['times'], 'Ohne Anzahl darf kein Ende entstehen.');
    }

    /** Und mit Tagen davor eine Testphase. */
    #[Test]
    public function a_trial_travels_with_the_plan(): void
    {
        $this->product([
            'interval' => '1 month',
            'trial_days' => 14,
            'trial_amount_cent' => 0,
        ]);

        $plan = app(Subscriptions::class)->planFor('atemkurs');

        $this->assertSame(14, $plan['trial_days']);
        $this->assertSame(0, $plan['trial_amount_cent'], 'Null heisst kostenlos, nicht „nicht gesetzt".');
    }

    /**
     * Ein leeres `interval` ist kein Rhythmus.
     *
     * Der Schalter muss auch dann aus sein, wenn jemand die Spalte mit
     * Leerzeichen fuellt — ein Formular, das einen leeren String schickt, ist
     * der wahrscheinlichste Weg dorthin.
     */
    #[Test]
    public function a_blank_interval_switches_nothing_on(): void
    {
        $this->product(['interval' => '   ', 'times' => 3]);

        $this->assertNull(app(Subscriptions::class)->planFor('atemkurs'));
        $this->assertArrayNotHasKey('times', Product::query()->sole()->toCatalogueEntry());
    }
}
