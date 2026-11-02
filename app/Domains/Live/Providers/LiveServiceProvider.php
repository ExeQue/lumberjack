<?php

declare(strict_types=1);

namespace App\Domains\Live\Providers;

use App\Domains\Issues\Events\IssueCreated;
use App\Domains\Issues\Events\IssueEscalated;
use App\Domains\Issues\Events\IssueRegressed;
use App\Domains\Live\Listeners\AnnounceSurfacedIssue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class LiveServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen([IssueCreated::class, IssueRegressed::class, IssueEscalated::class], AnnounceSurfacedIssue::class);
    }
}
