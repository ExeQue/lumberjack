<?php

declare(strict_types=1);

namespace App\Domains\Logs\Enums;

/**
 * PSR-3 levels with Monolog's numeric severities, so ordering is numeric.
 */
enum LogLevel: int
{
    case Debug = 100;
    case Info = 200;
    case Notice = 250;
    case Warning = 300;
    case Error = 400;
    case Critical = 500;
    case Alert = 550;
    case Emergency = 600;

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->name, $name) === 0) {
                return $case;
            }
        }

        return self::Info;
    }

    public function key(): string
    {
        return strtolower($this->name);
    }

    /**
     * Lines at error and above also become issues.
     */
    public function createsIssue(): bool
    {
        return $this->value >= self::Error->value;
    }
}
