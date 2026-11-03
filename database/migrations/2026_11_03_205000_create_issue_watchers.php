<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who watches which issue and why. An explicit unwatch keeps the row with unwatched_at
 * set, so acting on the issue later does not quietly start watching it again. Updates
 * waiting for a watcher's grouped mail live in issue_watch_updates until it goes out.
 * Users choose per kind whether they hear by mail, by toast, or both.
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

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });

        Schema::dropIfExists('issue_watch_updates');
        Schema::dropIfExists('issue_watchers');
    }
};
