<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Eloquent\Builders\AiBudgetBuilder;
use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetPeriod;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Alerts\Models\AiBudgetState;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\AiBudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How much AI a project, some keys, some key owners or some actors may use per day or
 * month, in the team's currency or in tokens. The AI budget alert rule tells as it fills up.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string|null $name
 * @property AiBudgetScope $scope
 * @property AiBudgetPeriod $period
 * @property AiBudgetBasis $basis
 * @property AiBudgetPayer $payer whose usage it counts, by who pays for it
 * @property float $amount
 * @property int $version raised by every change to what it counts or its amount
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, AiBudgetTarget> $targets
 *
 * @phpstan-import-type Only from AiCostQuery
 */
#[Fillable(['project_id', 'name', 'scope', 'period', 'basis', 'payer', 'amount'])]
#[UseEloquentBuilder(AiBudgetBuilder::class)]
#[UseFactory(AiBudgetFactory::class)]
class AiBudget extends Model
{
    /** @use HasFactory<AiBudgetFactory> */
    use HasFactory;
    use HasUuids;
    /** Names a title lists before it says how many more. */
    public const int TITLE_NAMES = 3;

    /** @var array<string, mixed> */
    protected $attributes = ['version' => 0];

    /** @var Collection<int, AiKey>|null */
    private ?Collection $loadedKeys = null;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'scope' => AiBudgetScope::class,
            'period' => AiBudgetPeriod::class,
            'basis' => AiBudgetBasis::class,
            'payer' => AiBudgetPayer::class,
            'amount' => 'float',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * What it counts: keys by fingerprint, or key owners or actors by type and id.
     *
     * @return HasMany<AiBudgetTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(AiBudgetTarget::class);
    }

    /**
     * The fingerprints of the keys a key budget counts.
     *
     * @return list<string>
     */
    public function fingerprints(): array
    {
        return $this->scope === AiBudgetScope::Key
            ? array_values($this->targets->map(fn (AiBudgetTarget $target): string => (string) $target->fingerprint)->sort()->all())
            : [];
    }

    /**
     * The key owners or actors an owner or actor budget counts.
     *
     * @return list<array{type: string, id: string}>
     */
    public function references(): array
    {
        return in_array($this->scope, [AiBudgetScope::Owner, AiBudgetScope::Actor], true)
            ? array_values($this->targets
                ->map(fn (AiBudgetTarget $target): array => ['type' => (string) $target->ref_type, 'id' => (string) $target->ref_id])
                ->sortBy(fn (array $reference): string => $reference['type'].':'.$reference['id'])
                ->all())
            : [];
    }

    /**
     * Where its alerts stand, per period.
     *
     * @return HasMany<AiBudgetState, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(AiBudgetState::class);
    }

    /**
     * The usage it counts, as AiCostQuery filters it.
     *
     * @return Only
     */
    public function usageFilter(): array
    {
        return match ($this->scope) {
            AiBudgetScope::Project => [],
            AiBudgetScope::Key => ['fingerprint' => $this->fingerprints()],
            AiBudgetScope::Owner => ['owner' => $this->references()],
            AiBudgetScope::Actor => ['actor' => $this->references()],
        };
    }

    /**
     * Its name, or what it counts: "Project", "Keys prod-primary, 1a2b3c4d", "Key owners tenant:7, tenant:8".
     */
    public function title(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return (string) match ($this->scope) {
            AiBudgetScope::Project => __('alerts.ai_budget.titles.project'),
            AiBudgetScope::Key => trans_choice('alerts.ai_budget.titles.keys', count($this->fingerprints()), ['keys' => self::list(array_map(
                fn (string $fingerprint): string => $this->keys()->get($fingerprint)?->displayName() ?? substr($fingerprint, 0, 8),
                $this->fingerprints(),
            ))]),
            AiBudgetScope::Owner, AiBudgetScope::Actor => trans_choice('alerts.ai_budget.titles.'.$this->scope->value.'s', count($this->references()), [
                'references' => self::list(array_map(fn (array $reference): string => $reference['type'].':'.$reference['id'], $this->references())),
            ]),
        };
    }

    /**
     * The first few names, and how many more: "a, b, c and 4 more".
     *
     * @param  list<string>  $names
     */
    private static function list(array $names): string
    {
        $shown = implode(', ', array_slice($names, 0, self::TITLE_NAMES));

        return count($names) > self::TITLE_NAMES
            ? (string) __('alerts.ai_budget.titles.more', ['names' => $shown, 'count' => count($names) - self::TITLE_NAMES])
            : $shown;
    }

    /**
     * The keys a key budget counts that the project still knows, by fingerprint.
     *
     * @return Collection<string, AiKey>
     */
    public function keys(): Collection
    {
        if ($this->loadedKeys === null) {
            $this->usingKeys($this->fingerprints() === [] ? new Collection() : AiKey::query()->whereProject($this->project_id)->whereIn('fingerprint', $this->fingerprints())->get());
        }

        /** @var Collection<string, AiKey> */
        return $this->loadedKeys->keyBy('fingerprint');
    }

    /**
     * Sets the keys it counts, found with others' at once, so a list of budgets looks keys
     * up in one query; ones it does not count are left out.
     *
     * @param  Collection<int, AiKey>  $keys
     */
    public function usingKeys(Collection $keys): static
    {
        $this->loadedKeys = $keys->whereIn('fingerprint', $this->fingerprints())->values();

        return $this;
    }

    /** An amount as the budget counts it: "82.50 EUR" or "1,200,000 tokens". */
    public function format(float $value): string
    {
        return $this->basis === AiBudgetBasis::Cost
            ? number_format($value, 2).' '.$this->project->team->currency
            : trans_choice('alerts.ai_budget.tokens', (int) round($value), ['count' => number_format($value)]);
    }

    /**
     * The totals it is counted from: actors are only in the daily ones, which hold a row
     * per actor; the rest from the fewer hourly rows, which pruning keeps for the whole
     * current month.
     *
     * @return AiCostQuery::HOURLY|AiCostQuery::DAILY
     */
    public function table(): string
    {
        return $this->scope === AiBudgetScope::Actor ? AiCostQuery::DAILY : AiCostQuery::HOURLY;
    }
}
