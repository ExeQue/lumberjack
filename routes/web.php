<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\AdminAuditController;
use App\Domains\Administration\Http\Controllers\AdminTeamController;
use App\Domains\Administration\Http\Controllers\AdminUserController;
use App\Domains\Administration\Http\Controllers\SetupController;
use App\Domains\Administration\Http\Middleware\EnsureSetupIsOpen;
use App\Domains\Administration\Http\Middleware\EnsureUserIsAdmin;
use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Commands\Http\Controllers\CommandController;
use App\Domains\Issues\Http\Controllers\IssueActionController;
use App\Domains\Issues\Http\Controllers\IssueController;
use App\Domains\Issues\Http\Controllers\LabelController;
use App\Domains\Jobs\Http\Controllers\JobController;
use App\Domains\Logs\Http\Controllers\LogChannelController;
use App\Domains\Logs\Http\Controllers\LogController;
use App\Domains\Mcp\Http\Controllers\McpTokenController;
use App\Domains\Overview\Http\Controllers\OverviewController;
use App\Domains\Performance\Http\Controllers\QueryController;
use App\Domains\Performance\Http\Controllers\RouteController;
use App\Domains\Performance\Http\Controllers\TraceController;
use App\Domains\Projects\Http\Controllers\ProjectAgentController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Controllers\ProjectSettingsController;
use App\Domains\Projects\Http\Middleware\FollowProjectTeam;
use App\Domains\Releases\Http\Controllers\ReleaseController;
use App\Domains\Repositories\Http\Controllers\RepositoryController;
use App\Domains\Retention\Http\Controllers\RetentionController;
use App\Domains\Schedule\Http\Controllers\ScheduleController;
use App\Domains\Search\Http\Controllers\SearchController;
use App\Domains\Teams\Http\Controllers\InvitationController;
use App\Domains\Teams\Http\Controllers\MemberController;
use App\Domains\Teams\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/projects')->name('home');

// First-run setup and recovery; 404 wherever it is not open. See docs/administration.md.
Route::get('setup', [SetupController::class, 'show'])->middleware(['throttle:30,1', EnsureSetupIsOpen::class, 'guest'])->name('setup');
Route::post('setup', [SetupController::class, 'store'])->middleware(['throttle:10,1', EnsureSetupIsOpen::class, 'guest'])->name('setup.store');

Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitations.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => redirect()->route('projects.index'))->name('dashboard');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

    Route::prefix('projects/{project}')->scopeBindings()->middleware(FollowProjectTeam::class)->group(function () {
        Route::get('/', OverviewController::class)->name('projects.show');
        Route::delete('/', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
        Route::post('issues/status', [IssueActionController::class, 'bulkStatus'])->name('issues.bulk-status');
        Route::get('issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
        Route::put('issues/{issue}/status', [IssueActionController::class, 'status'])->name('issues.status');
        Route::put('issues/{issue}/assignee', [IssueActionController::class, 'assign'])->name('issues.assign');
        Route::put('issues/{issue}/labels', [IssueActionController::class, 'labels'])->name('issues.labels');
        Route::post('issues/{issue}/notes', [IssueActionController::class, 'note'])->name('issues.notes.store');
        Route::post('issues/{issue}/repo-issue', [IssueActionController::class, 'repoIssue'])->name('issues.repo-issue');

        Route::get('routes', RouteController::class)->name('routes.index');
        Route::get('queries', QueryController::class)->name('queries.index');
        Route::get('releases', ReleaseController::class)->name('releases.index');
        Route::get('traces/{trace}', TraceController::class)->where('trace', '[A-Za-z0-9._:-]{1,64}')->name('traces.show');
        Route::get('jobs', JobController::class)->name('jobs.index');
        Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
        Route::get('commands', CommandController::class)->name('commands.index');
        Route::delete('schedule/{scheduledTask}', [ScheduleController::class, 'destroy'])->name('schedule.destroy');
        Route::get('logs', LogController::class)->name('logs.index');
        Route::get('search', SearchController::class)->middleware('throttle:120,1')->name('projects.search');

        Route::get('settings', [ProjectSettingsController::class, 'edit'])->name('projects.settings');
        Route::put('settings', [ProjectSettingsController::class, 'update'])->name('projects.settings.update');
        Route::post('settings/server-token', [ProjectSettingsController::class, 'rotateToken'])->name('projects.rotate-token');
        Route::post('settings/public-key', [ProjectSettingsController::class, 'rotatePublicKey'])->name('projects.rotate-public-key');
        Route::post('settings/log-channels', [LogChannelController::class, 'store'])->name('log-channels.store');
        Route::delete('settings/log-channels/{logChannel}', [LogChannelController::class, 'destroy'])->name('log-channels.destroy');
        Route::delete('settings/agents/{agent}', [ProjectAgentController::class, 'destroy'])->name('agents.destroy');
        Route::put('settings/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
        Route::delete('settings/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');
        Route::put('settings/retention', [RetentionController::class, 'project'])->name('projects.retention');
        Route::put('settings/repository', [RepositoryController::class, 'update'])->name('repository.update');
        Route::post('settings/repository/test', [RepositoryController::class, 'test'])->name('repository.test');
        Route::delete('settings/repository', [RepositoryController::class, 'destroy'])->name('repository.destroy');
    });

    // System administration: every team and user on this Lumberjack.
    Route::prefix('admin')->middleware(EnsureUserIsAdmin::class)->group(function () {
        Route::get('teams', [AdminTeamController::class, 'index'])->name('admin.teams.index');
        Route::post('teams', [AdminTeamController::class, 'store'])->name('admin.teams.store');
        Route::post('teams/{team}/open', [AdminTeamController::class, 'open'])->name('admin.teams.open');
        Route::delete('teams/{team}', [AdminTeamController::class, 'destroy'])->name('admin.teams.destroy');
        Route::get('users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('users/invite', [AdminUserController::class, 'invite'])->name('admin.users.invite');
        Route::put('users/{user}/suspended', [AdminUserController::class, 'suspend'])->name('admin.users.suspend');
        Route::put('users/{user}/admin', [AdminUserController::class, 'admin'])->name('admin.users.admin');
        Route::put('users/{user}/password-change', [AdminUserController::class, 'requirePasswordChange'])->name('admin.users.password-change');
        Route::post('users/{user}/password-reset', [AdminUserController::class, 'sendReset'])->name('admin.users.reset');
        Route::get('audit', AdminAuditController::class)->name('admin.audit');
    });

    Route::get('team', [TeamController::class, 'show'])->name('team.show');
    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
    Route::put('teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
    Route::put('team/retention', [RetentionController::class, 'team'])->name('team.retention');
    Route::put('team/members/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('team/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
    Route::post('team/members/{member}/ownership', [MemberController::class, 'transfer'])->name('members.transfer');
    Route::post('team/invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::post('team/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
    Route::delete('team/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
    Route::post('team/mcp-tokens', [McpTokenController::class, 'store'])->name('mcp-tokens.store');
    Route::delete('team/mcp-tokens/{token}', [McpTokenController::class, 'destroy'])->name('mcp-tokens.destroy');

    Route::get('team/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('team/alerts/channels', [AlertController::class, 'storeChannel'])->name('alerts.channels.store');
    Route::put('team/alerts/channels/{channel}', [AlertController::class, 'updateChannel'])->name('alerts.channels.update');
    Route::delete('team/alerts/channels/{channel}', [AlertController::class, 'destroyChannel'])->name('alerts.channels.destroy');
    Route::post('team/alerts/channels/{channel}/test', [AlertController::class, 'testChannel'])->name('alerts.channels.test');
    Route::put('team/alerts/rules/{rule}', [AlertController::class, 'updateRule'])->name('alerts.rules.update');
});

require __DIR__.'/settings.php';
