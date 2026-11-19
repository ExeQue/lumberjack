<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What AI tokens cost, and who pays for them. Cost itself is never stored: it is worked
 * out from the hourly and daily totals and these prices whenever it is read, so a price
 * entered late still prices the usage before it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            // ISO 4217; every price of the team is in it.
            $table->string('currency', 3)->default('USD');
        });

        Schema::table('projects', function (Blueprint $table): void {
            // Who pays for a key that says nothing itself: us or customer.
            $table->string('ai_default_payer', 16)->default('us');
        });

        Schema::table('ai_keys', function (Blueprint $table): void {
            // Null: inherited (see AiPayer).
            $table->string('payer', 16)->nullable();
        });

        Schema::create('ai_prices', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            // Null: every project of the team.
            $table->foreignIdFor(Project::class)->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 16);
            // An owner's type and key, or a key's fingerprint as the id.
            $table->string('scope_type', 128)->nullable();
            $table->string('scope_id', 64)->nullable();
            $table->string('provider', 64)->nullable();
            // A model name, or a glob with *.
            $table->string('model_pattern', 128);
            $table->timestamp('valid_from');
            // Per million tokens, in the team's currency.
            $table->decimal('input', 18, 6);
            $table->decimal('output', 18, 6);
            // Null: the input price.
            $table->decimal('cache_read', 18, 6)->nullable();
            $table->decimal('cache_write', 18, 6)->nullable();
            // Null: billed as output, which reasoning is part of.
            $table->decimal('reasoning', 18, 6)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prices');

        Schema::table('ai_keys', function (Blueprint $table): void {
            $table->dropColumn('payer');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('ai_default_payer');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
