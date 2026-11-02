<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // An ignored issue comes back at this time, at this many occurrences, or when it escalates.
            $table->timestamp('ignore_until_at')->nullable()->after('ignored_at');
            $table->unsignedBigInteger('ignore_until_count')->nullable()->after('ignore_until_at');
            $table->boolean('ignore_until_escalation')->default(false)->after('ignore_until_count');
            $table->timestamp('returned_at')->nullable()->after('ignore_until_escalation');
            $table->index(['status', 'ignore_until_at']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['status', 'ignore_until_at']);
            $table->dropColumn(['ignore_until_at', 'ignore_until_count', 'ignore_until_escalation', 'returned_at']);
        });
    }
};
