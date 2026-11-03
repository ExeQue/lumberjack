<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The domain a request was made to (shop.example.com next to api.example.com), and the
 * Laravel route name. Request runs get their domain back from their URL; the counts
 * have no URL, so their existing rows keep '' like a batch without an environment.
 */
return new class () extends Migration {
    /** Rows backfilled per statement, so a large table is not locked in one go. */
    public const int CHUNK = 10_000;

    /** The backfill commits chunk by chunk. */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('request_runs', function (Blueprint $table) {
            $table->string('domain')->nullable();
            $table->string('route_name')->nullable();
            $table->index(['project_id', 'domain', 'occurred_at']);
        });

        Schema::table('request_counts', function (Blueprint $table) {
            // Empty, not null, so the upsert key matches counts sent without one.
            $table->string('domain')->default('');
            $table->dropUnique(['project_id', 'environment', 'minute', 'method', 'route', 'status_code']);
            $table->unique(['project_id', 'environment', 'minute', 'method', 'domain', 'route', 'status_code']);
        });

        $this->backfill();
    }

    /**
     * The host of each run's URL, lowercased, without a user:password@ part.
     */
    public function backfill(): void
    {
        $max = (int) DB::table('request_runs')->max('id');

        for ($from = 0; $from < $max; $from += self::CHUNK) {
            DB::table('request_runs')
                ->whereNull('domain')
                ->where('id', '>', $from)
                ->where('id', '<=', $from + self::CHUNK)
                ->update(['domain' => DB::raw("NULLIF(LOWER(SUBSTRING(url FROM '^[A-Za-z][A-Za-z0-9+.-]*://(?:[^@/?#]*@)?([^/?#]+)')), '')")]);
        }
    }

    public function down(): void
    {
        Schema::table('request_counts', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'environment', 'minute', 'method', 'domain', 'route', 'status_code']);
            $table->dropColumn('domain');
            $table->unique(['project_id', 'environment', 'minute', 'method', 'route', 'status_code']);
        });

        Schema::table('request_runs', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'domain', 'occurred_at']);
            $table->dropColumn(['domain', 'route_name']);
        });
    }
};
