<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A separate threshold for down: degraded after `degrade_after` bad results in a row,
 * down only after `down_after` failed ones. Existing checks get the default (null).
 * Hosts count failed results on their own, and remember what they recover from.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('health_checks', function (Blueprint $table) {
            $table->unsignedSmallInteger('down_after')->nullable()->after('degrade_after');
        });

        Schema::table('health_check_hosts', function (Blueprint $table) {
            $table->unsignedInteger('failed_streak')->default(0)->after('fail_streak');
            $table->string('recovering_from', 16)->nullable()->after('status');
        });

        // Best effort: a host whose latest result failed has failed for its whole bad streak.
        DB::table('health_check_hosts')->where('last_status', 'failed')->update(['failed_streak' => DB::raw('fail_streak')]);
    }

    public function down(): void
    {
        Schema::table('health_check_hosts', function (Blueprint $table) {
            $table->dropColumn(['failed_streak', 'recovering_from']);
        });

        Schema::table('health_checks', function (Blueprint $table) {
            $table->dropColumn('down_after');
        });
    }
};
