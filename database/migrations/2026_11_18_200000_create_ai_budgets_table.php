<?php

declare(strict_types=1);

use App\Domains\Ai\Models\AiBudget;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI budgets per project, where each stands in its period for the budget alert rule, and
 * keys whose calls fail too often for the error rate rule; both rules for every team, on.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_budgets', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            // What it counts: project, key, owner or actor; the keys, owners or actors are in ai_budget_targets.
            $table->string('scope', 16);
            $table->string('period', 8);
            $table->string('basis', 8);
            // Whose usage it counts by who pays for it: all, us or customer.
            $table->string('payer', 16);
            // In the team's currency, or tokens.
            $table->decimal('amount', 18, 6);
            // Raised by every change to what it counts or its amount, so an alert check that
            // measured an older one writes nothing.
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        // What a budget counts: keys by fingerprint (so it keeps counting a key the project
        // forgot), or key owners or actors by type and id; its scope says which.
        Schema::create('ai_budget_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(AiBudget::class)->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64)->nullable();
            $table->string('ref_type', 128)->nullable();
            $table->string('ref_id', 64)->nullable();

            $table->unique(['ai_budget_id', 'fingerprint', 'ref_type', 'ref_id'])->nullsNotDistinct();
            // A key's, owner's or actor's page shows the budgets that count it.
            $table->index(['fingerprint', 'ai_budget_id']);
            $table->index(['ref_type', 'ref_id', 'ai_budget_id']);
        });

        // A target is one kind: a key, or a whole type and id.
        DB::statement('ALTER TABLE ai_budget_targets ADD CONSTRAINT ai_budget_targets_one_kind CHECK ((fingerprint IS NOT NULL AND ref_type IS NULL AND ref_id IS NULL) OR (fingerprint IS NULL AND ref_type IS NOT NULL AND ref_id IS NOT NULL))');

        Schema::create('ai_budget_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(AiBudget::class)->constrained()->cascadeOnDelete();
            $table->timestamp('period_start');
            // The thresholds (percent) already told about in this period.
            $table->json('fired_thresholds');
            $table->timestamp('projected_fired_at')->nullable();
            // When the period's last look was taken: kept for its alerts' jobs, not looked at again.
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_budget_id', 'period_start']);
        });

        Schema::create('ai_error_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(AlertRule::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->timestamp('breaching_since')->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();

            $table->unique(['alert_rule_id', 'project_id', 'fingerprint']);
        });

        $now = now();

        foreach (['ai_budget', 'ai_error_rate'] as $type) {
            DB::table('alert_rules')->insertOrIgnore(DB::table('teams')->pluck('id')->map(fn (int $team): array => [
                'team_id' => $team,
                'type' => $type,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        DB::table('alert_rules')->whereIn('type', ['ai_budget', 'ai_error_rate'])->delete();
        Schema::dropIfExists('ai_error_rates');
        Schema::dropIfExists('ai_budget_states');
        Schema::dropIfExists('ai_budget_targets');
        Schema::dropIfExists('ai_budgets');
    }
};
