<?php

declare(strict_types=1);

namespace App\Domains\Logs\Eloquent\Builders;

use App\Domains\Logs\Enums\LogLevel;
use App\Domains\Logs\Models\LogEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<LogEntry>
 */
class LogEntryBuilder extends Builder
{
    public function whereLevelAtLeast(LogLevel $level): self
    {
        return $this->where('level', '>=', $level->value);
    }

    public function orWhereLevelAtLeast(LogLevel $level): self
    {
        return $this->orWhere('level', '>=', $level->value);
    }

    public function whereMessageMatches(string $term): self
    {
        return $this->whereFullText('message', $term);
    }

    public function orWhereMessageMatches(string $term): self
    {
        return $this->orWhereFullText('message', $term);
    }
}
