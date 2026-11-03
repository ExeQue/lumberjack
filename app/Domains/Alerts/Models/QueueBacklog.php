<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one queue stands for one queue backlog rule: over the thresholds since when,
 * and whether the rule has told about it.
 *
 * @property int $id
 * @property int $alert_rule_id
 * @property string $connection
 * @property string $queue
 * @property CarbonImmutable|null $breaching_since
 * @property CarbonImmutable|null $fired_at
 * @property CarbonImmutable|null $last_fired_at
 */
#[Fillable(['alert_rule_id', 'connection', 'queue', 'breaching_since', 'fired_at', 'last_fired_at'])]
class QueueBacklog extends Model
{
    protected function casts(): array
    {
        return [
            'breaching_since' => 'datetime',
            'fired_at' => 'datetime',
            'last_fired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }
}
