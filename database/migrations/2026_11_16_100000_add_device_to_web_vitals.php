<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device class a page view was measured on (mobile, tablet or desktop); null for
 * a browser script that did not send one.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('web_vitals', function (Blueprint $table) {
            $table->string('device', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('web_vitals', function (Blueprint $table) {
            $table->dropColumn('device');
        });
    }
};
