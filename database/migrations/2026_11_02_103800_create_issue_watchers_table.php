<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who watches an issue, and why.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('issue_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('reason', 16);
            $table->timestamp('unwatched_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['issue_id', 'user_id']);
            $table->index(['user_id', 'unwatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_watchers');
    }
};
