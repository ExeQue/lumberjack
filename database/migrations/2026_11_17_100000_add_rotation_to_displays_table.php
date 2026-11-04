<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How far a display's picture is turned, for a TV hung sideways that cannot turn it itself. */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('displays', function (Blueprint $table) {
            $table->unsignedSmallInteger('rotation')->default(0)->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('displays', function (Blueprint $table) {
            $table->dropColumn('rotation');
        });
    }
};
