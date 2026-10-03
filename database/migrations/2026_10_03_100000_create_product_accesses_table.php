<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zugaenge: was ein Kauf freischaltet.
 *
 * Ein Produkt traegt in `grants` nur Slugs. Was hinter einem Slug steckt
 * (Kurse, Dateien, Termine, Guthaben, Mitgliederbereich), stand bisher auf der
 * Website in drei Collections und einer PHP-Konstante. Diese Tabelle fuehrt es
 * im Addon. Ausgeliefert wird weiter von den Schwester-Addons.
 *
 * Eigener Datensatz und kein Feld am Produkt, weil jeder Vergabeweg (Kasse,
 * ThriveCart, Vergabe von Hand, Import) nur den Slug kennt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_accesses', function (Blueprint $table) {
            $table->id();

            // Der Grant-Slug. Eindeutig ueber alle Marken, wie der Produkt-Handle:
            // `entitlements.product_slug` kennt keine Marke.
            $table->string('handle', 191)->unique();

            $table->string('name', 191);
            $table->text('description')->nullable();

            // Ein Asset (`container::pfad`) oder eine URL. Freitext, weil das
            // Konto der Website es anzeigt, nicht dieses Addon.
            $table->string('cover', 512)->nullable();

            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('brand_id')->default(0)->index();

            $table->boolean('opens_members_area')->default(false);

            // Geordnet. Bei Dateien ist die Position Teil der Download-Kennung.
            $table->json('contents')->nullable();

            // Guthabenzeilen. `line` darin ist fest und Teil des
            // Idempotenzschluessels auf der Website.
            $table->json('credits')->nullable();

            // Wie viele Zeilennummern je vergeben wurden. Die naechste neue Zeile
            // bekommt diese Zahl. Eine eigene Spalte statt `max(line) + 1`, weil
            // eine geloeschte hoechste Zeile ihre Nummer sonst wieder hergaebe.
            $table->unsignedInteger('credit_lines_issued')->default(0);

            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_accesses');
    }
};
