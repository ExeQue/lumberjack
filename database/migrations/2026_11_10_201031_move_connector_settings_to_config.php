<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A service connection's settings move into one encrypted JSON column, so a new kind of
 * service needs no columns of its own; only the name it is shown by stays readable.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('data_source_connections', function (Blueprint $table) {
            $table->string('name', 200)->nullable()->after('kind');
            $table->text('config')->nullable()->after('name');
        });

        DB::table('data_source_connections')->where('kind', 'umami')->orderBy('id')->each(function (object $row): void {
            try {
                $secret = is_string($row->secret) ? Crypt::decryptString($row->secret) : '';
            } catch (DecryptException) {
                // Saved under another APP_KEY: the connection asks for its credentials again.
                $secret = '';
            }

            DB::table('data_source_connections')->where('id', $row->id)->update([
                'name' => $row->website_name,
                'config' => Crypt::encryptString(json_encode(['website_id' => (string) $row->website_id, 'username' => $row->username, 'secret' => $secret], JSON_THROW_ON_ERROR)),
            ]);
        });

        Schema::table('data_source_connections', function (Blueprint $table) {
            $table->dropColumn(['website_id', 'website_name', 'username', 'secret']);
        });
    }

    public function down(): void
    {
        Schema::table('data_source_connections', function (Blueprint $table) {
            $table->string('website_id', 64)->nullable();
            $table->string('website_name', 200)->nullable();
            $table->string('username', 200)->nullable();
            $table->text('secret')->nullable();
        });

        DB::table('data_source_connections')->where('kind', 'umami')->orderBy('id')->each(function (object $row): void {
            $config = json_decode(Crypt::decryptString((string) $row->config), true);

            DB::table('data_source_connections')->where('id', $row->id)->update([
                'website_id' => $config['website_id'] ?? null,
                'website_name' => $row->name,
                'username' => $config['username'] ?? null,
                'secret' => Crypt::encryptString((string) ($config['secret'] ?? '')),
            ]);
        });

        Schema::table('data_source_connections', function (Blueprint $table) {
            $table->dropColumn(['name', 'config']);
        });
    }
};
