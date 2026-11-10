<?php

declare(strict_types=1);

use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Mcp\Http\Controllers\McpTokenController;
use App\Domains\Retention\Http\Controllers\RetentionController;
use App\Domains\Teams\Http\Controllers\InvitationController;
use App\Domains\Teams\Http\Controllers\MemberController;
use App\Domains\Teams\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

/*
 * The current team: members, invitations, MCP tokens, retention and alerts. Loaded inside the auth group.
 */

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
Route::put('team/mcp-tokens/{token}', [McpTokenController::class, 'update'])->name('mcp-tokens.update');
Route::delete('team/mcp-tokens/{token}', [McpTokenController::class, 'destroy'])->name('mcp-tokens.destroy');

Route::get('team/alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::post('team/alerts/channels', [AlertController::class, 'storeChannel'])->name('alerts.channels.store');
Route::put('team/alerts/channels/{channel}', [AlertController::class, 'updateChannel'])->name('alerts.channels.update');
Route::delete('team/alerts/channels/{channel}', [AlertController::class, 'destroyChannel'])->name('alerts.channels.destroy');
Route::post('team/alerts/channels/{channel}/test', [AlertController::class, 'testChannel'])->middleware('throttle:6,1')->name('alerts.channels.test');
Route::put('team/alerts/rules/{rule}', [AlertController::class, 'updateRule'])->name('alerts.rules.update');
Route::post('team/alerts/topics', [AlertController::class, 'storeTopic'])->name('alerts.topics.store');
Route::delete('team/alerts/topics/{rule}', [AlertController::class, 'destroyTopic'])->name('alerts.topics.destroy');
Route::delete('team/alerts/rules/{rule}', [AlertController::class, 'resetRule'])->name('alerts.rules.reset');
