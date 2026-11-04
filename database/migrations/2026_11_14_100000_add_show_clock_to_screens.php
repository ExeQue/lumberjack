<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Whether the header shows the clock, and a screen without a time zone of its own (the viewer's). */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->boolean('show_clock')->default(true)->after('show_header');
            $table->string('timezone', 64)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('show_clock');
        });
    }
};
