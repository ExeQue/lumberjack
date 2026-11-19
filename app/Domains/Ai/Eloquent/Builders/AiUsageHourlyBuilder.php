<?php

declare(strict_types=1);

namespace App\Domains\Ai\Eloquent\Builders;

use App\Domains\Ai\Eloquent\Builders\Concerns\FiltersByFingerprint;
use App\Domains\Ai\Models\AiUsageHourly;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<AiUsageHourly>
 * @mixin Builder<AiUsageHourly>
 */
class AiUsageHourlyBuilder extends Builder
{
    use FiltersByFingerprint;
}
