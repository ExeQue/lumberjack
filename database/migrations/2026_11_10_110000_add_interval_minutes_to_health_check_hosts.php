<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often the app says a reported check runs on a host, from its results'
 * interval_minutes. Null for results that do not send it (older SDKs).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('health_check_hosts', function (Blueprint $table) {
            $table->unsignedSmallInteger('interval_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('health_check_hosts', function (Blueprint $table) {
            $table->dropColumn('interval_minutes');
        });
    }
};
