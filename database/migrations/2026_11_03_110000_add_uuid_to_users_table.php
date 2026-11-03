<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Users get a uuid like projects and teams, so their broadcast channel names no
 * row id.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid()->nullable()->after('id');
        });

        DB::table('users')->whereNull('uuid')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['uuid' => (string) Str::uuid7()]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid()->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
