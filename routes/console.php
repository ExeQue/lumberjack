<?php

declare(strict_types=1);

use App\Domains\Administration\Console\Commands\CheckForUpdateCommand;
use App\Domains\Alerts\Console\Commands\CheckQueueBacklogsCommand;
use App\Domains\DataSources\Console\Commands\FetchDataSourcesCommand;
use App\Domains\Health\Console\Commands\ArchiveSilentHealthChecksCommand;
use App\Domains\Health\Console\Commands\RunHealthChecksCommand;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Issues\Console\Commands\ReturnIgnoredIssuesCommand;
use App\Domains\Issues\Console\Commands\SendWatchDigestsCommand;
use App\Domains\Jobs\Console\Commands\MarkStuckJobsCommand;
use App\Domains\Projects\Console\Commands\NotifyOfflineAgentsCommand;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Retention\Console\Commands\PruneTelemetryCommand;
use App\Domains\Schedule\Console\Commands\MarkMissedScheduledTasksCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(MarkStuckJobsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(MarkMissedScheduledTasksCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(ReturnIgnoredIssuesCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(SendWatchDigestsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(CheckQueueBacklogsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(RunHealthChecksCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(FetchDataSourcesCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(NotifyOfflineAgentsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(CheckForUpdateCommand::class)->everySixHours()->withoutOverlapping();
Schedule::command(ArchiveSilentHealthChecksCommand::class)->daily()->withoutOverlapping();
Schedule::command(PruneTelemetryCommand::class)->dailyAt('03:15')->timezone('Europe/Copenhagen')->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('model:prune', ['--model' => [ProjectAgent::class, HealthCheckHost::class]])->hourly()->withoutOverlapping();
