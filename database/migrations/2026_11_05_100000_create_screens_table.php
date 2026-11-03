<?php

declare(strict_types=1);

use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status screens: a team's wall displays. The widgets are an ordered JSON list (no table
 * per widget), flowed into a grid of cols x rows cells; scope is the screen's default
 * projects, environment and period, which each widget may override.
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
            $table->string('timezone', 64)->default('UTC');
            $table->json('scope');
            $table->json('widgets');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screens');
    }
};
