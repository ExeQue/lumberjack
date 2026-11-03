<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Whether the TV shows the screen's header (name, scope, live, clock) above the grid. */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->boolean('show_header')->default(true)->after('fit');
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('show_header');
        });
    }
};
