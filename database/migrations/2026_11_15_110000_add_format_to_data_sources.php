<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a data source answers: JSON, as every source so far, or Prometheus text.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('format', 20)->default('json')->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
