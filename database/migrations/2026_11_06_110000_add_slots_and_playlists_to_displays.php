<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One computer, several screens: a display is one window (slot 1 to 9, /tv/{slot}) of a
 * browser, recognized as the same computer by a device cookie (only its sha256 kept).
 * And a display may show several screens in turn: a playlist of screen ids.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('displays', function (Blueprint $table) {
            $table->unsignedTinyInteger('slot')->default(1);
            $table->string('device_hash', 64)->nullable()->index();
            $table->json('playlist')->nullable();
            $table->unsignedSmallInteger('rotate_seconds')->default(30);
            $table->boolean('hold_on_alert')->default(true);
        });

        Schema::table('tv_pairings', function (Blueprint $table) {
            $table->unsignedTinyInteger('slot')->default(1);
            $table->string('device_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tv_pairings', function (Blueprint $table) {
            $table->dropColumn(['slot', 'device_hash']);
        });

        Schema::table('displays', function (Blueprint $table) {
            $table->dropColumn(['slot', 'device_hash', 'playlist', 'rotate_seconds', 'hold_on_alert']);
        });
    }
};
