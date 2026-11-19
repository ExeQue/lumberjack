<?php

declare(strict_types=1);

use App\Domains\Projects\Support\ProjectKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The secret AI key fingerprints are made with (an HMAC of the hash the app sends), encrypted
     * with APP_KEY. Never rotated: a new one would give every key a new fingerprint.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->text('ai_key_secret')->nullable();
        });

        DB::table('projects')->whereNull('ai_key_secret')->select('id')->eachById(function (object $project): void {
            DB::table('projects')->where('id', $project->id)->update(['ai_key_secret' => Crypt::encryptString(ProjectKeys::newAiKeySecret())]);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->text('ai_key_secret')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('ai_key_secret');
        });
    }
};
