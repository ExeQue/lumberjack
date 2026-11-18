<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The room around a screen's grid and between its widgets (ScreenSpacing).
     */
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->string('margin', 4)->default('m')->after('fit');
            $table->string('gap', 4)->default('m')->after('margin');
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropColumn(['margin', 'gap']);
        });
    }
};
