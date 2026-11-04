<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to a watched issue, waiting for the watcher's grouped mail.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('issue_watch_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('actor');
            $table->json('data')->nullable();
            $table->text('body')->nullable();
            $table->json('reasons')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'issue_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_watch_updates');
    }
};
