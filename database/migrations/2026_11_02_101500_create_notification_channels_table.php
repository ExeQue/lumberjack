<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where alerts are sent. No project: shared by the whole team; a project: only its rules can use it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name');
            $table->text('config');
            $table->string('last_delivery_status', 16)->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();
            $table->foreignIdFor(Project::class)->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
