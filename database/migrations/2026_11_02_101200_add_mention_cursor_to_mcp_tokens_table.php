<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table) {
            // The last note that tagged this token and was handed to its agent.
            $table->unsignedBigInteger('mention_cursor_id')->nullable()->after('inbox_cursor_id');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table) {
            $table->dropColumn('mention_cursor_id');
        });
    }
};
