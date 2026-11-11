<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Since when a period has been asked for without a break: a health check that starts reading a period nobody kept pulled times its first pull from it, not from an old one.
        Schema::table('data_source_windows', function (Blueprint $table): void {
            $table->timestamp('wanted_since')->nullable()->after('requested_at');
        });

        // requested_at is the last ask, not the first: a period already pulled counts from its last pull.
        DB::table('data_source_windows')->update(['wanted_since' => DB::raw('LEAST(requested_at, COALESCE(fetched_at, requested_at))')]);
    }

    public function down(): void
    {
        Schema::table('data_source_windows', function (Blueprint $table): void {
            $table->dropColumn('wanted_since');
        });
    }
};
