<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How a screen shows on a display of another shape (ScreenFit), keeping its own. */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->string('fit', 8)->default('keep')->after('orientation');
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('fit');
        });
    }
};
