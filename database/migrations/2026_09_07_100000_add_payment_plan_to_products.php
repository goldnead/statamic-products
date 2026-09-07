<?php

use Goldnead\StatamicPayments\Support\Subscriptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der Rhythmus, in dem ein Produkt bezahlt wird.
     *
     * **Warum das fehlte, und was daran ein Rueckschritt war.**
     * `statamic-payments` kann Abo, Ratenzahlung und Testphase seit 1.5.0 —
     * ein Mechanismus, drei Gesichter: `times = null` ist ein Abo, `times = N`
     * eine Ratenzahlung, `trial_days` eine Testphase
     * ({@see Subscriptions::planFor()}).
     * Gelesen wird der Plan aus dem Katalog.
     *
     * Solange der Katalog eine Config war, stand er dort und funktionierte.
     * Seit dieses Addon die Produkte in eine Tabelle geholt hat, gibt
     * `Product::toCatalogueEntry()` genau `handle`, `name`, `amount_cent`,
     * `currency`, `digital` und `grants` weiter — und damit **konnte eine
     * Zeile aus der Datenbank keinen Plan mehr tragen**. `planFor()` gab fuer
     * jedes Tabellen-Produkt `null` zurueck, ohne Fehler und ohne Log.
     *
     * Aufgefallen ist es am 06.09.2026 an einer konkreten Stelle: die
     * ChoirAccelerator-Seite verspricht „2 x 780 EUR oder 3 x 520 EUR", und
     * beim Umzug des Kaufs auf den eigenen Funnel stellte sich heraus, dass
     * die eigene Kasse das nicht anbieten kann. Nicht, weil die Faehigkeit
     * fehlt — sie war beim Katalog-Umzug still verlorengegangen.
     *
     * **Alle vier Spalten sind nullable, und `interval` ist der Schalter.**
     * `planFor()` steigt aus, sobald `interval` leer ist; ein Produkt ohne
     * Rhythmus verhaelt sich damit exakt wie vorher. Eine Migration, die
     * Standardwerte setzte, machte aus jedem bestehenden Produkt ein Abo.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /*
             * Im Wortlaut des Anbieters: `1 month`, `12 weeks`, `2 days`.
             *
             * Absichtlich eine Zeichenkette und keine Aufzaehlung.
             * `Subscriptions::afterOneInterval()` reicht den Wert an Carbon
             * weiter und faellt bei Unlesbarem auf einen Monat zurueck, statt
             * zu werfen — dieselbe Freiheit gehoert in die Spalte, sonst
             * beschneidet das Katalog-Addon, was das Zahlungs-Addon kann.
             */
            $table->string('interval', 32)->nullable()->after('amount_cent');

            /*
             * Wie oft abgebucht wird. `null` heisst „ohne Ende" — ein Abo.
             * Eine Zahl heisst Ratenzahlung: `times = 3` sind drei Raten,
             * fertig.
             *
             * Unsigned, weil eine negative Anzahl keine Frage beantwortet.
             * Die Null faengt `planFor()` selbst ab („nichts abbuchen, nie"
             * ist ein Tippfehler, keine Anweisung) — hier steht sie deshalb
             * nicht im Weg.
             */
            $table->unsignedSmallInteger('times')->nullable()->after('interval');

            /*
             * Testphase in Tagen, bevor die erste volle Abbuchung faellt.
             */
            $table->unsignedSmallInteger('trial_days')->nullable()->after('times');

            /*
             * Was die Testphase kostet, in kleinster Einheit. `null` heisst
             * „der gewoehnliche Betrag" — wer eine billige Probe will, sagt
             * das mit einer Zahl und weiss dann, dass er abbucht. `0` ist
             * erlaubt und heisst kostenlos.
             */
            $table->integer('trial_amount_cent')->nullable()->after('trial_days');
        });
    }

    /**
     * Ohne Tabelle nichts zu tun.
     *
     * `CatalogueTest::a_missing_table_answers_empty_instead_of_taking_the_checkout_down`
     * loescht `products` absichtlich, um zu belegen, dass eine fehlende
     * Tabelle die Kasse nicht mitreisst. Beim Aufraeumen laeuft diese
     * Migration rueckwaerts und wuerde ohne die Wache genau das tun, was der
     * Test ausschliesst: an einer fehlenden Tabelle zerbrechen.
     */
    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['interval', 'times', 'trial_days', 'trial_amount_cent']);
        });
    }
};
