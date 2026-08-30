<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kinds shipped in 1.0.0 with German values. Every sibling stores English.
     *
     * `statamic-payments` stores `paid`, `open`, `expired`. `statamic-offers`
     * stores `bump`, `post_purchase`, `standalone`. `statamic-booking` stores
     * `booked`, `cancelled`. This addon shipped `zugang`, `termin`, `sitzungen`
     * and `kohorte` — German values in an English codebase, sitting in a column
     * a buyer reads in their own database.
     *
     * Found by the studio's audit, hours after 1.0.0 went out and before anyone
     * had installed it. A stored value is semver-locked the moment somebody
     * does, so this is the last cheap moment to fix it — and it is still worth
     * a migration rather than a silent break, because "nobody has installed it"
     * is an assumption and a rewrite is not.
     *
     * @var array<string, string>
     */
    private const RENAMED = [
        'zugang' => 'access',
        'termin' => 'event',
        'sitzungen' => 'sessions',
        'kohorte' => 'cohort',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'type')) {
            return;
        }

        foreach (self::RENAMED as $old => $new) {
            DB::table('products')->where('type', $old)->update(['type' => $new]);
        }
    }

    /**
     * Reversible, because a rollback that leaves values the old code cannot read
     * is not a rollback. `RefTarget` answers "unknowable" for a kind it does not
     * know, so nothing would break loudly — it would simply stop checking every
     * pointer of those kinds, which is the quiet half of a failure.
     */
    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'type')) {
            return;
        }

        foreach (self::RENAMED as $old => $new) {
            DB::table('products')->where('type', $new)->update(['type' => $old]);
        }
    }
};
