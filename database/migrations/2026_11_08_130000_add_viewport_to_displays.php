<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The size a paired TV reports for its window, for the designer's preview. */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('displays', function (Blueprint $table) {
            $table->unsignedSmallInteger('viewport_width')->nullable()->after('ip');
            $table->unsignedSmallInteger('viewport_height')->nullable()->after('viewport_width');
            $table->decimal('pixel_ratio', 4, 2)->nullable()->after('viewport_height');
        });
    }

    public function down(): void
    {
        Schema::table('displays', function (Blueprint $table) {
            $table->dropColumn(['viewport_width', 'viewport_height', 'pixel_ratio']);
        });
    }
};
