<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's linked code repository and the token to reach it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('project_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('repository');
            $table->text('token');
            $table->date('token_expires_at')->nullable();
            $table->json('labels')->nullable();
            $table->boolean('allow_public')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_repositories');
    }
};
