<?php

declare(strict_types=1);

namespace App\Domains\Ai\Eloquent\Builders;

use App\Domains\Ai\Eloquent\Builders\Concerns\FiltersByActorAndOwner;
use App\Domains\Ai\Eloquent\Builders\Concerns\FiltersByFingerprint;
use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Models\AiCall;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<AiCall>
 * @mixin Builder<AiCall>
 */
class AiCallBuilder extends Builder
{
    use FiltersByActorAndOwner;
    use FiltersByFingerprint;

    public function whereFailed(): self
    {
        return $this->where('status', AiCallStatus::Error->value);
    }

    public function orWhereFailed(): self
    {
        return $this->orWhere('status', AiCallStatus::Error->value);
    }
}
