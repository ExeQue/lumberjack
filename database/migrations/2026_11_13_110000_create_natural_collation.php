<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A collation that sorts digits by their value ("user 2" before "user 10"), for
 * ORDER BY ... COLLATE "natural".
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::statement("CREATE COLLATION IF NOT EXISTS \"natural\" (provider = icu, locale = 'und-u-kn-true')");
    }

    public function down(): void
    {
        DB::statement('DROP COLLATION IF EXISTS "natural"');
    }
};
