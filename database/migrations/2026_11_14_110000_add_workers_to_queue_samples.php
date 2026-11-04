<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How many workers ran on the queue, where the agent knows (from the workers' signs of life). */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('queue_samples', function (Blueprint $table) {
            $table->unsignedInteger('workers')->nullable()->after('oldest_age_s');
        });
    }

    public function down(): void
    {
        Schema::table('queue_samples', function (Blueprint $table) {
            $table->dropColumn('workers');
        });
    }
};
