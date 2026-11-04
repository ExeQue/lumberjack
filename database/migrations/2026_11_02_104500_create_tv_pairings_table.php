<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Display;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived codes a TV shows so a user can pair it with a display.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tv_pairings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('code', 6)->unique();
            $table->string('secret_hash', 64)->unique();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at')->index();
            $table->foreignIdFor(Display::class)->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unsignedTinyInteger('slot')->default(1);
            $table->string('device_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_pairings');
    }
};
