<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('data_source_connections', function (Blueprint $table): void {
            $table->string('kind', 16)->default('app')->after('project_id');
            $table->string('website_id', 64)->nullable();
            $table->string('website_name', 200)->nullable();
            $table->string('username', 200)->nullable();
            $table->text('secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('data_source_connections', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'website_id', 'website_name', 'username', 'secret']);
        });
    }
};
