<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One window of a paired device, showing one of its team's screens or several in turn.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('displays', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Screen::class)->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60)->nullable();
            $table->string('theme', 16)->default('default');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unsignedTinyInteger('slot')->default(1);
            $table->json('playlist')->nullable();
            $table->unsignedSmallInteger('rotate_seconds')->default(30);
            $table->boolean('hold_on_alert')->default(true);
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();
            $table->decimal('pixel_ratio', 4, 2)->nullable();
            $table->foreignIdFor(Device::class)->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('displays');
    }
};
