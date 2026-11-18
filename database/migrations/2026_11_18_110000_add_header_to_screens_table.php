<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * What the header shows: parts hidden, a title of its own, the clock's date and
     * seconds (Screen::header()). Null: all of it, as before.
     */
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->json('header')->nullable()->after('clock_format');
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropColumn('header');
        });
    }
};
