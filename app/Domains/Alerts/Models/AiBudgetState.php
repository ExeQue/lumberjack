<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Ai\Models\AiBudget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the AI budget rule told about one budget in one period: the thresholds it crossed,
 * and whether it warned of an overrun to come. A new period has no row yet, so each
 * threshold tells once per period.
 *
 * @property int $id
 * @property int $ai_budget_id
 * @property CarbonImmutable $period_start
 * @property list<int> $fired_thresholds
 * @property CarbonImmutable|null $projected_fired_at
 * @property CarbonImmutable|null $closed_at the period's last look taken
 */
#[Fillable(['ai_budget_id', 'period_start', 'fired_thresholds', 'projected_fired_at', 'closed_at'])]
class AiBudgetState extends Model
{
    protected $attributes = ['fired_thresholds' => '[]'];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'fired_thresholds' => 'array',
            'projected_fired_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiBudget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(AiBudget::class, 'ai_budget_id');
    }
}
