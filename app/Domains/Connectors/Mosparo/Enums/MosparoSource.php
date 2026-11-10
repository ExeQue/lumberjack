<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Enums;

use App\Domains\DataSources\Enums\DataSourceShape;

/**
 * The data sources a mosparo project offers screens.
 */
enum MosparoSource: string
{
    case Valid = 'valid';
    case Spam = 'spam';
    case SpamRate = 'spam_rate';
    case Delayed = 'delayed';
    case Blocked = 'blocked';
    case ValidSeries = 'valid.series';
    case SpamSeries = 'spam.series';

    public function label(): string
    {
        return __('connectors.mosparo.sources.'.str_replace('.', '_', $this->value));
    }

    public function shape(): DataSourceShape
    {
        return str_ends_with($this->value, '.series') ? DataSourceShape::Series : DataSourceShape::Number;
    }

    /**
     * Its figure from a period's totals: a count, or the share of submissions that were spam.
     *
     * @param  array{valid: int, spam: int, delayed: int, blocked: int}  $totals
     */
    public function figure(array $totals): int|float|null
    {
        $submissions = $totals['valid'] + $totals['spam'];

        return match ($this) {
            self::SpamRate => $submissions > 0 ? round($totals['spam'] / $submissions * 100, 1) : null,
            self::ValidSeries, self::Valid => $totals['valid'],
            self::SpamSeries, self::Spam => $totals['spam'],
            self::Delayed => $totals['delayed'],
            self::Blocked => $totals['blocked'],
        };
    }
}
