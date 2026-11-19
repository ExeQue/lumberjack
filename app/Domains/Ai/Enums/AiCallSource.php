<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * What reported an AI call: an SDK integration, the app by hand, or an OpenTelemetry span.
 */
enum AiCallSource: string
{
    case LaravelAi = 'laravel-ai';
    case Prism = 'prism';
    case Manual = 'manual';
    case Otel = 'otel';

    public function label(): string
    {
        return __('ui/ai.enums.source.'.$this->value);
    }
}
