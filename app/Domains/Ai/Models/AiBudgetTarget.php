<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the things a budget counts: a key by fingerprint for a key budget, a key owner or
 * an actor by type and id for the others.
 *
 * @property int $id
 * @property int $ai_budget_id
 * @property string|null $fingerprint
 * @property string|null $ref_type
 * @property string|null $ref_id
 * @property-read AiBudget $budget
 */
#[Fillable(['ai_budget_id', 'fingerprint', 'ref_type', 'ref_id'])]
class AiBudgetTarget extends Model
{
    public $timestamps = false;

    /**
     * How a form names a key the project forgot, which only its budget still knows: by its
     * short id, so the fingerprint stays on the server.
     */
    public static function forgotten(string $fingerprint): string
    {
        return 'forgotten:'.substr($fingerprint, 0, 8);
    }

    /** @return BelongsTo<AiBudget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(AiBudget::class, 'ai_budget_id');
    }
}
