<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // What the apps last answered: asked for again as soon as a screen shows it.
        DB::table('data_source_windows')->delete();

        Schema::table('data_source_windows', function (Blueprint $table): void {
            $table->unsignedInteger('bucket')->after('period');
            $table->dropUnique(['data_source_id', 'period']);
            $table->unique(['data_source_id', 'period', 'bucket']);
        });
    }

    public function down(): void
    {
        DB::table('data_source_windows')->delete();

        Schema::table('data_source_windows', function (Blueprint $table): void {
            $table->dropUnique(['data_source_id', 'period', 'bucket']);
            $table->dropColumn('bucket');
            $table->unique(['data_source_id', 'period']);
        });
    }
};
