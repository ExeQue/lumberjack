<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The header's clock in 24 or 12 hours.
     */
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->string('clock_format', 2)->default('24')->after('show_clock');
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropColumn('clock_format');
        });
    }
};
