<?php

declare(strict_types=1);

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->char('token_hash', 64)->unique();
            $table->string('scope', 8);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('inbox_cursor_at', 3)->nullable();
            $table->unsignedBigInteger('inbox_cursor_id')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_token_project', function (Blueprint $table) {
            $table->foreignIdFor(McpToken::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();

            $table->primary(['mcp_token_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_token_project');
        Schema::dropIfExists('mcp_tokens');
    }
};
