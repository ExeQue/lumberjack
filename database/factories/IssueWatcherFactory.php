<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueWatcher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueWatcher>
 */
class IssueWatcherFactory extends Factory
{
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'user_id' => User::factory(),
            'reason' => WatchReason::Manual,
        ];
    }

    public function because(WatchReason $reason): static
    {
        return $this->state(fn () => ['reason' => $reason]);
    }
}
