<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The thing that is sold.
     *
     * Until now a product lived in three places and none of them knew what it
     * was: `statamic-offers` knew how to present one, `statamic-payments` knew
     * what one costs — a line in a config file, with no screen — and
     * `statamic-entitlements` knew that somebody had access to one, as a free
     * string. The thing itself existed nowhere, so every site invented it
     * again. On adriangoldner.com it was invented twice, as `member_packages`
     * and `access_packages`, and the two drifted.
     *
     * This table is the missing middle and nothing more. It does not deliver
     * anything: what a course *shows* stays the website's business. It says
     * that a course exists, what it costs, and what it opens.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            /*
             * The name a payment, an invoice line and an offer refer to.
             *
             * **Unique across every brand, deliberately.** A handle is not
             * scoped by tenant even though the row is, because the places that
             * read it back have no tenant: a provider webhook arriving hours
             * later, an invoice reprinted next year, a payment row that has
             * carried this string since the sale. Scoping the name would mean
             * resolving a price required knowing whose request it was, and the
             * one context that never knows is the one that takes the money.
             *
             * An agency with three brands therefore names its products apart.
             * That is a real cost, and it is smaller than a webhook that cannot
             * price what it was sent.
             */
            $table->string('handle', 191)->unique();

            // What is bought. Goes into the order confirmation and onto the
            // invoice (§ 312j BGB), so it is a sentence a buyer recognises and
            // not an internal label.
            $table->string('name', 191);

            /*
             * The list price. Not nullable: a product without a price is not a
             * product, it is a note to self.
             *
             * Unsigned, so zero is possible and negative is not. Zero is a real
             * answer — the lead magnet, the sample chapter — and the catalogue
             * in `statamic-payments` has allowed it since it learned that
             * refusing free things pushed every free thing outside the addon.
             *
             * An offer may undercut this. Nobody else may.
             */
            $table->unsignedInteger('amount_cent');

            // Null means "whatever the install is configured in". Stored rather
            // than assumed only where a product really is sold in another
            // currency than the rest of the shop.
            $table->string('currency', 3)->nullable();

            /*
             * A tax fact, not a description of the medium.
             *
             * It decides the place of supply and with it the mandatory notice
             * (§ 3a UStG): a downloadable workbook is taxed where the buyer is,
             * a workshop in a room is taxed where the room is. A recording of
             * that workshop is digital; the workshop is not.
             *
             * **No default, on purpose.** Every default here is wrong for half
             * the catalogue, and a wrong default is invisible — it shows up as
             * a tax line nobody checked. Whoever creates a product answers it.
             */
            $table->boolean('digital');

            /*
             * What a paid copy opens, as a list.
             *
             * `statamic-entitlements` takes slugs and stays deliberately
             * ignorant of what a product is; this is the column that finally
             * gives those slugs something to point back at. A list rather than
             * a string because one product may open three things — the bundle
             * work in `statamic-payments` 1.14 taught the payment side the same
             * lesson.
             *
             * Nullable: a product that opens nothing is normal. A printed score
             * posted in an envelope grants no access at all.
             */
            $table->json('grants')->nullable();

            /*
             * Retired rather than deleted.
             *
             * A product that has been sold cannot be removed: its handle is on
             * payment rows and invoice lines that must still render years
             * later. Without this flag the only way to stop selling something
             * is to delete it, and that is how an old invoice starts showing a
             * blank line.
             */
            $table->boolean('active')->default(true)->index();

            // Zero on every single-brand install, which is nearly all of them.
            // Same shape as `payments` and `subscriptions`, so an agency with
            // three brands gets three catalogues in one Control Panel.
            $table->unsignedBigInteger('brand_id')->default(0)->index();

            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
