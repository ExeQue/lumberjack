<?php

declare(strict_types=1);

use App\Domains\Alerts\Console\Commands\CheckQueueBacklogsCommand;
use App\Domains\Health\Console\Commands\ArchiveSilentHealthChecksCommand;
use App\Domains\Health\Console\Commands\RunHealthChecksCommand;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Issues\Console\Commands\ReturnIgnoredIssuesCommand;
use App\Domains\Jobs\Console\Commands\MarkStuckJobsCommand;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Retention\Console\Commands\PruneTelemetryCommand;
use App\Domains\Schedule\Console\Commands\MarkMissedScheduledTasksCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(MarkStuckJobsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(MarkMissedScheduledTasksCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(ReturnIgnoredIssuesCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(CheckQueueBacklogsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(RunHealthChecksCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(ArchiveSilentHealthChecksCommand::class)->daily()->withoutOverlapping();
Schedule::command(PruneTelemetryCommand::class)->dailyAt('03:15')->timezone('Europe/Copenhagen')->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('model:prune', ['--model' => [ProjectAgent::class, QueueSample::class, HostSample::class, HealthCheckHost::class]])->hourly()->withoutOverlapping();
