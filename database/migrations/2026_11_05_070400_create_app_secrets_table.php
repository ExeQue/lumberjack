<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secrets Lumberjack makes for itself when the environment sets none, such as the VAPID
 * key pair for browser push. Values are encrypted with APP_KEY.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('app_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_secrets');
    }
};
