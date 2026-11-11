<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One connection per repository and project, so two connects at once cannot make two.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::statement("create unique index data_source_connections_github_repository_unique on data_source_connections (project_id, name) where kind = 'github'");
    }

    public function down(): void
    {
        DB::statement('drop index data_source_connections_github_repository_unique');
    }
};
