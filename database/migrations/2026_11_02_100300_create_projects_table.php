<?php

declare(strict_types=1);

use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An app that reports to Lumberjack, with its ingest token, public key and public stats.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->char('token_hash', 64)->unique();
            $table->char('token_last_four', 4);
            $table->string('public_key', 40)->unique();
            $table->json('allowed_origins')->nullable();
            $table->timestamps();
            $table->json('scrub_keys')->nullable();
            $table->string('issue_prefix', 8)->nullable();
            $table->unsignedInteger('last_issue_number')->default(0);
            $table->string('stats_key', 64)->nullable()->unique();
            $table->boolean('stats_include_titles')->default(false);

            $table->unique(['team_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
