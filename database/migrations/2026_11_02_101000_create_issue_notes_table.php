<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes on an issue, by a user or an MCP token.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('issue_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('mcp_token_id')->nullable();
            $table->text('body');
            $table->timestamps();
            // The status the issue was given with this note, e.g. a comment on resolving.
            $table->string('status', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_notes');
    }
};
