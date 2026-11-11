<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // The maps themselves are files on the default disk, under sourcemaps/{project id}/.
        Schema::create('source_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('release', 64)->nullable();
            $table->unsignedInteger('size');
            $table->timestamps();
            $table->unique(['project_id', 'path', 'release'])->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_maps');
    }
};
