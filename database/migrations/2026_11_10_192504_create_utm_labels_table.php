<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSourceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('utm_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(DataSourceConnection::class)->constrained()->cascadeOnDelete();
            $table->string('dimension', 16);
            $table->string('value', 500);
            $table->string('label', 100);
            $table->timestamps();
            $table->unique(['data_source_connection_id', 'dimension', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utm_labels');
    }
};
