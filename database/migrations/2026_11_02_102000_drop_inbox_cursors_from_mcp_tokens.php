<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The inbox no longer keeps a cursor: an issue stays in a token's inbox until the
     * token writes a note on it, so a run that fails halfway loses nothing.
     */
    public function up(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table) {
            $table->dropColumn(['inbox_cursor_at', 'inbox_cursor_id', 'mention_cursor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table) {
            $table->timestamp('inbox_cursor_at')->nullable();
            $table->unsignedBigInteger('inbox_cursor_id')->nullable();
            $table->unsignedBigInteger('mention_cursor_id')->nullable();
        });
    }
};
