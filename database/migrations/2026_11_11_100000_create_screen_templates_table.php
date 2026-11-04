<?php

declare(strict_types=1);

use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates a team saved from its screens, offered by New screen next to the built-in
 * ones: the screen's portable file (ExportScreenAction), so a template outlives the
 * screen and resolves its projects and checks by slug when used.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('screen_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->jsonb('definition');
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_templates');
    }
};
