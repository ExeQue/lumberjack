<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('query_runs', function (Blueprint $table) {
            // The line in the app's code that ran the query, e.g. app/Models/Order.php:42.
            $table->string('location')->nullable()->after('sql_hash');
        });
    }

    public function down(): void
    {
        Schema::table('query_runs', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
