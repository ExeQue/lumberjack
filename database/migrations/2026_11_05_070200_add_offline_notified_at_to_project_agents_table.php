<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When members were told an agent went offline, so they are told once; its next heartbeat clears it. */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('project_agents', function (Blueprint $table) {
            $table->timestamp('offline_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_agents', function (Blueprint $table) {
            $table->dropColumn('offline_notified_at');
        });
    }
};
