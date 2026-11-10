<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table): void {
            $table->string('filter_value', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table): void {
            $table->dropColumn('filter_value');
        });
    }
};
