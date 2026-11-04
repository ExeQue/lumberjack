<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\AdminAuditController;
use App\Domains\Administration\Http\Controllers\AdminTeamController;
use App\Domains\Administration\Http\Controllers\AdminUserController;
use App\Domains\Administration\Http\Controllers\SetupController;
use App\Domains\Administration\Http\Middleware\EnsureSetupIsOpen;
use App\Domains\Administration\Http\Middleware\EnsureUserIsAdmin;
use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Alerts\Http\Controllers\QueueAlertThresholdController;
use App\Domains\Commands\Http\Controllers\CommandController;
use App\Domains\Health\Http\Controllers\HealthController;
use App\Domains\Issues\Http\Controllers\IssueActionController;
use App\Domains\Issues\Http\Controllers\IssueController;
use App\Domains\Issues\Http\Controllers\LabelController;
use App\Domains\Issues\Http\Controllers\WatchingController;
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
use App\Domains\Screens\Http\Controllers\DeviceController;
use App\Domains\Screens\Http\Controllers\DisplayController;
use App\Domains\Screens\Http\Controllers\PairController;
use App\Domains\Screens\Http\Controllers\ScreenController;
use App\Domains\Screens\Http\Controllers\ScreenFileController;
use App\Domains\Screens\Http\Controllers\ScreenShareController;
use App\Domains\Screens\Http\Controllers\SharedScreenController;
use App\Domains\Screens\Http\Controllers\TvController;
use App\Domains\Search\Http\Controllers\SearchController;
use App\Domains\Shared\Http\Controllers\MailPreviewController;
use App\Domains\Teams\Http\Controllers\InvitationController;
use App\Domains\Teams\Http\Controllers\MemberController;
use App\Domains\Teams\Http\Controllers\TeamController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/projects')->name('home');

// First-run setup and recovery; 404 wherever it is not open. See docs/administration.md.
Route::get('setup', [SetupController::class, 'show'])->middleware(['throttle:30,1', EnsureSetupIsOpen::class, 'guest'])->name('setup');
Route::post('setup', [SetupController::class, 'store'])->middleware(['throttle:10,1', EnsureSetupIsOpen::class, 'guest'])->name('setup.store');

Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');

// The signed "Stop watching" link of watching mails, without signing in. POST is also the
// mail client's one-click unsubscribe (List-Unsubscribe-Post), so it skips CSRF.
Route::get('watch/{issue}/stop', [WatchingController::class, 'confirm'])->middleware(['signed', 'throttle:30,1'])->name('watching.stop');
Route::post('watch/{issue}/stop', [WatchingController::class, 'stop'])->middleware(['signed', 'throttle:30,1'])->withoutMiddleware(ValidateCsrfToken::class)->name('watching.stop.confirm');
Route::post('invitations/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitations.accept');

// A TV's start page and what it asks without a session, by its httpOnly cookies; and a
// screen by its share link. See docs/screens.md.
Route::get('tv/{slot?}', [TvController::class, 'show'])->whereIn('slot', range(1, 9))->middleware('throttle:30,1')->name('tv');
Route::prefix('tv/{slot}')->whereIn('slot', range(1, 9))->middleware('throttle:screen-viewer')->group(function () {
    Route::post('pairing', [TvController::class, 'claim'])->name('tv.claim');
    Route::post('restore', [TvController::class, 'restore'])->withoutMiddleware('throttle:screen-viewer')->middleware('throttle:10,1')->name('tv.restore');
    Route::get('data', [TvController::class, 'data'])->name('tv.data');
    Route::get('heartbeat', [TvController::class, 'heartbeat'])->name('tv.heartbeat');
});
Route::get('s/{key}', [SharedScreenController::class, 'show'])->where('key', 'ls_[a-z0-9]{40}')->middleware('throttle:screen-viewer')->name('screens.shared');
Route::get('s/{key}/data', [SharedScreenController::class, 'data'])->where('key', 'ls_[a-z0-9]{40}')->middleware('throttle:screen-viewer')->name('screens.shared.data');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => redirect()->route('projects.index'))->name('dashboard');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

    Route::prefix('projects/{project}')->scopeBindings()->middleware(FollowProjectTeam::class)->group(function () {
        Route::get('/', OverviewController::class)->name('projects.show');
        Route::delete('/', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
        Route::post('issues/status', [IssueActionController::class, 'bulkStatus'])->name('issues.bulk-status');
        Route::post('issues/assignee', [IssueActionController::class, 'bulkAssign'])->name('issues.bulk-assign');
        Route::post('issues/labels', [IssueActionController::class, 'bulkLabels'])->name('issues.bulk-labels');
        Route::post('issues/watch', [WatchingController::class, 'bulk'])->name('issues.bulk-watch');
        Route::get('issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
        Route::put('issues/{issue}/status', [IssueActionController::class, 'status'])->name('issues.status');
        Route::put('issues/{issue}/assignee', [IssueActionController::class, 'assign'])->name('issues.assign');
        Route::put('issues/{issue}/labels', [IssueActionController::class, 'labels'])->name('issues.labels');
        Route::put('issues/{issue}/watch', [WatchingController::class, 'update'])->name('issues.watch');
        Route::post('issues/{issue}/notes', [IssueActionController::class, 'note'])->name('issues.notes.store');
        Route::post('issues/{issue}/repo-issue', [IssueActionController::class, 'repoIssue'])->name('issues.repo-issue');

        Route::get('routes', RouteController::class)->name('routes.index');
        Route::get('queries', QueryController::class)->name('queries.index');
        Route::get('releases', ReleaseController::class)->name('releases.index');
        Route::get('traces/{trace}', TraceController::class)->where('trace', '[A-Za-z0-9._:-]{1,64}')->name('traces.show');
        Route::get('jobs', JobController::class)->name('jobs.index');
        Route::put('jobs/queue-alert', [QueueAlertThresholdController::class, 'update'])->name('queues.alert');
        Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
        Route::get('commands', CommandController::class)->name('commands.index');
        Route::delete('schedule/{scheduledTask}', [ScheduleController::class, 'destroy'])->name('schedule.destroy');
        Route::get('logs', LogController::class)->name('logs.index');
        Route::get('health', [HealthController::class, 'index'])->name('health.index');
        Route::post('health', [HealthController::class, 'store'])->name('health.store');
        Route::put('health/{healthCheck}', [HealthController::class, 'update'])->name('health.update');
        Route::put('health/{healthCheck}/archive', [HealthController::class, 'archive'])->name('health.archive');
        Route::delete('health/{healthCheck}', [HealthController::class, 'destroy'])->name('health.destroy');
        Route::get('search', SearchController::class)->middleware('throttle:120,1')->name('projects.search');

        Route::get('settings', [ProjectSettingsController::class, 'edit'])->name('projects.settings');
        Route::put('settings', [ProjectSettingsController::class, 'update'])->name('projects.settings.update');
        Route::post('settings/server-token', [ProjectSettingsController::class, 'rotateToken'])->name('projects.rotate-token');
        Route::post('settings/public-key', [ProjectSettingsController::class, 'rotatePublicKey'])->name('projects.rotate-public-key');
        Route::put('settings/public-stats', [ProjectSettingsController::class, 'updatePublicStats'])->name('projects.public-stats');
        Route::post('settings/public-stats/key', [ProjectSettingsController::class, 'rotateStatsKey'])->name('projects.rotate-stats-key');
        Route::post('settings/log-channels', [LogChannelController::class, 'store'])->name('log-channels.store');
        Route::delete('settings/log-channels/{logChannel}', [LogChannelController::class, 'destroy'])->name('log-channels.destroy');
        Route::delete('settings/agents/{agent}', [ProjectAgentController::class, 'destroy'])->name('agents.destroy');
        Route::put('settings/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
        Route::delete('settings/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');
        Route::put('settings/retention', [RetentionController::class, 'project'])->name('projects.retention');
        Route::post('settings/alert-rules/{type}', [AlertController::class, 'customizeRule'])->name('alerts.rules.customize');
        Route::put('settings/alert-rules/{rule}/add-ons', [AlertController::class, 'updateAddOns'])->withoutScopedBindings()->name('alerts.rules.add-ons');
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
        Route::get('retention', [RetentionController::class, 'show'])->name('admin.retention');
        Route::put('retention', [RetentionController::class, 'application'])->name('admin.retention.update');
    });

    Route::get('team', [TeamController::class, 'show'])->name('team.show');
    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
    Route::put('teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
    Route::put('team/retention', [RetentionController::class, 'team'])->name('team.retention');
    Route::get('team/retention', [RetentionController::class, 'teamShow'])->name('team.retention.show');
    Route::put('team/members/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('team/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
    Route::post('team/members/{member}/ownership', [MemberController::class, 'transfer'])->name('members.transfer');
    Route::post('team/invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::post('team/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
    Route::delete('team/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
    Route::post('team/mcp-tokens', [McpTokenController::class, 'store'])->name('mcp-tokens.store');
    Route::delete('team/mcp-tokens/{token}', [McpTokenController::class, 'destroy'])->name('mcp-tokens.destroy');

    // Status screens: the team's wall displays. See docs/screens.md.
    Route::get('screens', [ScreenController::class, 'index'])->name('screens.index');
    Route::post('screens', [ScreenController::class, 'store'])->name('screens.store');
    Route::post('screens/import', [ScreenFileController::class, 'import'])->middleware('throttle:20,1')->name('screens.import');
    Route::post('screens/import/preview', [ScreenFileController::class, 'preview'])->middleware('throttle:60,1')->name('screens.import.preview');
    Route::get('screens/{screen:uuid}/export', [ScreenFileController::class, 'export'])->name('screens.export');
    Route::get('screens/{screen:uuid}', [ScreenController::class, 'show'])->name('screens.show');
    Route::get('screens/{screen:uuid}/edit', [ScreenController::class, 'edit'])->name('screens.edit');
    Route::get('screens/{screen:uuid}/data', [ScreenController::class, 'data'])->middleware('throttle:120,1')->name('screens.data');
    Route::get('screens/{screen:uuid}/viewers', [ScreenController::class, 'viewers'])->name('screens.viewers');
    Route::put('screens/{screen:uuid}', [ScreenController::class, 'update'])->name('screens.update');
    Route::put('screens/{screen:uuid}/layout', [ScreenController::class, 'layout'])->name('screens.layout');
    Route::put('screens/{screen:uuid}/widgets/{widget}', [ScreenController::class, 'widget'])->where('widget', '[A-Za-z0-9_-]{1,40}')->name('screens.widget');
    Route::post('screens/{screen:uuid}/reload', [ScreenController::class, 'reload'])->name('screens.reload');
    Route::post('screens/{screen:uuid}/duplicate', [ScreenController::class, 'duplicate'])->name('screens.duplicate');
    Route::delete('screens/{screen:uuid}', [ScreenController::class, 'destroy'])->name('screens.destroy');
    Route::put('screens/{screen:uuid}/share', [ScreenShareController::class, 'update'])->name('screens.share');
    Route::post('screens/{screen:uuid}/share/rotate', [ScreenShareController::class, 'rotate'])->name('screens.share.rotate');
    Route::get('screens/{screen:uuid}/share/qr', [ScreenShareController::class, 'qr'])->name('screens.share.qr');

    // Pairing a TV by the code it shows, and the team's paired TVs.
    Route::get('pair', [PairController::class, 'index'])->name('pair');
    Route::get('pair/{code}', [PairController::class, 'show'])->where('code', '[A-Za-z0-9-]{1,12}')->middleware('throttle:tv-pair')->name('pair.show');
    Route::post('pair/{code}', [PairController::class, 'confirm'])->where('code', '[A-Za-z0-9-]{1,12}')->middleware('throttle:tv-pair')->name('pair.confirm');
    Route::get('team/displays', [DisplayController::class, 'index'])->name('team.displays');
    Route::put('team/displays/{display:uuid}', [DisplayController::class, 'update'])->name('displays.update');
    Route::post('team/displays/{display:uuid}/reload', [DisplayController::class, 'reload'])->name('displays.reload');
    Route::delete('team/displays/{display:uuid}', [DisplayController::class, 'destroy'])->name('displays.destroy');
    Route::put('team/devices/{device:uuid}', [DeviceController::class, 'update'])->name('devices.update');
    Route::post('team/devices/{device:uuid}/reload', [DeviceController::class, 'reload'])->name('devices.reload');
    Route::delete('team/devices/{device:uuid}', [DeviceController::class, 'destroy'])->name('devices.destroy');

    Route::get('team/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('team/alerts/channels', [AlertController::class, 'storeChannel'])->name('alerts.channels.store');
    Route::put('team/alerts/channels/{channel}', [AlertController::class, 'updateChannel'])->name('alerts.channels.update');
    Route::delete('team/alerts/channels/{channel}', [AlertController::class, 'destroyChannel'])->name('alerts.channels.destroy');
    Route::post('team/alerts/channels/{channel}/test', [AlertController::class, 'testChannel'])->middleware('throttle:6,1')->name('alerts.channels.test');
    Route::put('team/alerts/rules/{rule}', [AlertController::class, 'updateRule'])->name('alerts.rules.update');
    Route::delete('team/alerts/rules/{rule}', [AlertController::class, 'resetRule'])->name('alerts.rules.reset');
});

require __DIR__.'/settings.php';

// Every mail with sample data, light and dark; local development only. See docs/administration.md.
if (app()->environment('local')) {
    Route::middleware('auth')->prefix('dev/mail')->group(function () {
        Route::get('/', [MailPreviewController::class, 'index'])->name('dev.mail.index');
        Route::get('{name}', [MailPreviewController::class, 'show'])->name('dev.mail.show');
        Route::get('{name}/{scheme}', [MailPreviewController::class, 'frame'])->whereIn('scheme', ['light', 'dark'])->name('dev.mail.frame');
    });
}
