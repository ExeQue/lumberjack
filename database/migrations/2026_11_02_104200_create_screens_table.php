<?php

declare(strict_types=1);

use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboards for a TV: a grid of widgets over a scope of projects, optionally shared by link.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('screens', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('cols')->default(4);
            $table->unsignedTinyInteger('rows')->default(4);
            $table->string('theme', 16)->default('dark');
            $table->string('light_from', 5)->default('07:00');
            $table->string('light_to', 5)->default('19:00');
            $table->string('timezone', 64)->nullable();
            $table->json('scope');
            $table->json('widgets');
            $table->timestamps();
            $table->text('share_key')->nullable();
            $table->string('share_key_hash', 64)->nullable()->unique();
            $table->boolean('share_titles')->default(false);
            $table->string('aspect', 8)->default('16:9');
            $table->string('orientation', 16)->default('landscape');
            $table->string('fit', 8)->default('keep');
            $table->boolean('show_header')->default(true);
            $table->boolean('show_clock')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screens');
    }
};
