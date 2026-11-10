<?php

declare(strict_types=1);

use App\Domains\Mcp\Data\McpAreaGrant;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Schedule\DeleteScheduledTask;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Mcp\CallsMcpTools;

covers(DeleteScheduledTask::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')
        ->allowing(McpArea::Schedule, McpLevel::Manage)->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->task = ScheduledTask::factory()->for($this->project)->create(['name' => 'artisan reports:send', 'environment' => 'production']);
});

describe('deleting a scheduled task', function () {
    it('deletes the named task', function () {
        $this->callTool('lj_mcp_secret', 'delete_scheduled_task', ['name' => 'artisan reports:send'])->assertOk();

        expect(ScheduledTask::query()->whereKey($this->task->id)->exists())->toBeFalse();
    });

    it('asks for the environment when the name is in several', function () {
        ScheduledTask::factory()->for($this->project)->create(['name' => 'artisan reports:send', 'environment' => 'staging']);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_scheduled_task', ['name' => 'artisan reports:send'])))->toBeTrue();

        $this->callTool('lj_mcp_secret', 'delete_scheduled_task', ['name' => 'artisan reports:send', 'environment' => 'staging'])->assertOk();
        expect(ScheduledTask::query()->whereKey($this->task->id)->exists())->toBeTrue()
            ->and($this->project->scheduledTasks()->count())->toBe(1);
    });

    it('refuses a read token and a maker who may not change the project', function () {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)]);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_scheduled_task', ['name' => 'artisan reports:send'])))->toBeTrue();

        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)->with(McpArea::Schedule, new McpAreaGrant(McpLevel::Manage))]);
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_scheduled_task', ['name' => 'artisan reports:send'])))->toBeTrue()
            ->and(ScheduledTask::query()->whereKey($this->task->id)->exists())->toBeTrue();
    });
});
