<?php

declare(strict_types=1);

use App\Domains\Issues\Console\Commands\ReturnIgnoredIssuesCommand;
use App\Domains\Jobs\Console\Commands\MarkStuckJobsCommand;
use App\Domains\Retention\Console\Commands\PruneTelemetryCommand;
use App\Domains\Schedule\Console\Commands\MarkMissedScheduledTasksCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(MarkStuckJobsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(MarkMissedScheduledTasksCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(ReturnIgnoredIssuesCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(PruneTelemetryCommand::class)->dailyAt('03:15')->timezone('Europe/Copenhagen')->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
