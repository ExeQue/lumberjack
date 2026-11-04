<?php

declare(strict_types=1);

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The projects an MCP token is limited to.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mcp_token_project', function (Blueprint $table) {
            $table->foreignIdFor(McpToken::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();

            $table->primary(['mcp_token_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_token_project');
    }
};
