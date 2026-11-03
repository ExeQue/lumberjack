<?php

declare(strict_types=1);

namespace App\Domains\Issues\Providers;

use App\Domains\Issues\Events\IssueNoteAdded;
use App\Domains\Issues\Events\IssuesAssigned;
use App\Domains\Issues\Listeners\NotifyAssignees;
use App\Domains\Issues\Listeners\NotifyMentionedMembers;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class IssuesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(IssueNoteAdded::class, NotifyMentionedMembers::class);
        Event::listen(IssuesAssigned::class, NotifyAssignees::class);
    }
}
