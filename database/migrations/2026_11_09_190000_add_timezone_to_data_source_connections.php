<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('data_source_connections', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('UTC')->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('data_source_connections', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
