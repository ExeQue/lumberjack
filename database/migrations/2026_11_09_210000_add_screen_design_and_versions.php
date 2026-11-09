<?php

declare(strict_types=1);

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Screens\Models\Screen;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->boolean('can_design_screens')->default(false);
        });

        Schema::table('screens', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(0);
        });

        Schema::create('screen_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Screen::class)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(McpToken::class)->nullable()->constrained()->nullOnDelete();
            $table->string('author', 255)->nullable();
            $table->timestamp('created_at');
            $table->index(['screen_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_versions');

        Schema::table('screens', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });

        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->dropColumn('can_design_screens');
        });
    }
};
