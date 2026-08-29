<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What kind of thing this is, and which thing.
     *
     * **The kind is an answer, not an instruction.** It says a product *is* a
     * live date; it does not reserve a seat. That distinction is the whole
     * decision behind this addon: Kajabi and Podia make the product type the
     * delivery itself — the course type *is* the player, the coaching type *is*
     * the calendar — and going that way means building a course player, a
     * community engine, a scheduler and podcast hosting. That is not an addon
     * family any more, that is a platform.
     *
     * Here the delivery stays where it already is: on the website, and in the
     * sibling addons that do that job. Some of those do not exist yet. A
     * product whose kind points at one of them is still worth filing today —
     * which is why an unresolvable `ref` is *shown as unresolved* rather than
     * refused.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /*
             * One of `Product::types()`.
             *
             * A default only because rows exist that were written before kinds
             * did, and `download` is the honest answer for them: they carry no
             * pointer, and a thing you hand over with nothing behind it is a
             * download. New products still have to choose — that is enforced in
             * the form, where a person is present to answer, and not by a NOT
             * NULL column that would have made this migration impossible.
             */
            $table->string('type', 32)->default('download')->index()->after('name');

            /*
             * The pointer at the thing of that kind, in whatever spelling the
             * sibling that owns it uses: a Statamic entry id, an event's uuid,
             * a collection handle, a booking endpoint.
             *
             * Deliberately **not** a foreign key, and deliberately one column
             * rather than one per kind. Half of what it can point at lives in
             * another package's table, half in flat content files, and a
             * quarter of it is not installed on any given site. A join would
             * have to exist five times and would break the moment somebody
             * uninstalls a sibling.
             *
             * Null for a download, and null while somebody is still deciding.
             */
            $table->string('ref', 191)->nullable()->index();
        });
    }

    public function down(): void
    {
        // Guarded, because a rollback runs over whatever state it finds. The
        // create-migration beside this one may already have gone, or never have
        // run; a `down()` that assumes its own table is still there turns a
        // rollback into a hard error and leaves the rest of the batch stranded.
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'type')) {
                $table->dropIndex(['type']);
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('products', 'ref')) {
                $table->dropIndex(['ref']);
                $table->dropColumn('ref');
            }
        });
    }
};
