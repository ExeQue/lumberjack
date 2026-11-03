<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shape of the display a screen is designed for (ScreenAspect, ScreenOrientation);
 * every screen so far was designed on a 16:9 landscape grid.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->string('aspect', 8)->default('16:9')->after('rows');
            $table->string('orientation', 16)->default('landscape')->after('aspect');
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn(['aspect', 'orientation']);
        });
    }
};
