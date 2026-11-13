<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Enums;

use App\Domains\Shared\Enums\Period;
use Illuminate\Support\Str;

/**
 * Who a connection pulls from: an app with the Laravel package, signed with a pull token,
 * or an outside service read through its own API with the credentials it was given.
 */
enum ConnectionKind: string
{
    case App = 'app';
    case Umami = 'umami';
    case Mosparo = 'mosparo';
    case GitHub = 'github';
    case MobilePay = 'mobilepay';
    case Packagist = 'packagist';

    /** The service's name; an app's connection is named by its environment instead. */
    public function label(): ?string
    {
        return match ($this) {
            self::App => null,
            self::Umami => 'Umami',
            self::Mosparo => 'mosparo',
            self::GitHub => 'GitHub',
            self::MobilePay => 'Vipps MobilePay',
            self::Packagist => 'Packagist',
        };
    }

    /**
     * The period the service answers for in place of $period: mosparo and Packagist count
     * whole days and Vipps MobilePay books whole days, so an hour is read as the day.
     */
    public function period(Period $period): Period
    {
        return in_array($this, [self::Mosparo, self::MobilePay, self::Packagist], true) && $period === Period::Hour ? Period::Day : $period;
    }

    /**
     * The periods the service can answer for.
     *
     * @return list<Period>
     */
    public function periods(): array
    {
        return array_values(array_filter(Period::cases(), fn (Period $period): bool => $this->period($period) === $period));
    }

    /** A new environment for a service's connection, unique within the project. */
    public function environment(): string
    {
        return $this->value.'-'.Str::lower(Str::random(8));
    }

    /** Whether an environment is a service's, and so cannot be an app's. */
    public static function ownsEnvironment(string $environment): bool
    {
        foreach (self::cases() as $kind) {
            if ($kind !== self::App && preg_match('/^'.preg_quote($kind->value, '/').'(-|$)/i', $environment) === 1) {
                return true;
            }
        }

        return false;
    }
}
