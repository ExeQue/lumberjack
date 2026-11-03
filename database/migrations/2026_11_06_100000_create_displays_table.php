<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Displays: TVs paired to a team, each showing one of its screens. The TV holds a long
 * lived token as a cookie; only its sha256 is stored. A pairing is the short lived code a
 * TV shows until someone signed in confirms it, tied to the TV's own pairing cookie.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('displays', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Screen::class)->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->string('theme', 16)->default('default');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

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
        });

        Schema::table('screens', function (Blueprint $table) {
            $table->text('share_key')->nullable();
            $table->string('share_key_hash', 64)->nullable()->unique();
            $table->boolean('share_titles')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn(['share_key', 'share_key_hash', 'share_titles']);
        });
        Schema::dropIfExists('tv_pairings');
        Schema::dropIfExists('displays');
    }
};
