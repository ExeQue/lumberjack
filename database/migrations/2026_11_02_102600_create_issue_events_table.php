<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An issue's timeline: when it was created, changed status, regressed or escalated.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('mcp_token_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->index(['issue_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_events');
    }
};
