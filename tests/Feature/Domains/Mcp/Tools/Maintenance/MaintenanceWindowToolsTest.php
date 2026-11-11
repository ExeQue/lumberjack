<?php

declare(strict_types=1);

use App\Domains\Maintenance\Enums\MaintenanceSource;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Concerns\UsesMaintenanceWindows;
use App\Domains\Mcp\Tools\Maintenance\CreateMaintenanceWindow;
use App\Domains\Mcp\Tools\Maintenance\EndMaintenanceWindow;
use App\Domains\Mcp\Tools\Maintenance\ListMaintenanceWindows;
use App\Domains\Mcp\Tools\Maintenance\UpdateMaintenanceWindow;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Mcp\CallsMcpTools;

covers(ListMaintenanceWindows::class, CreateMaintenanceWindow::class, UpdateMaintenanceWindow::class, EndMaintenanceWindow::class, UsesMaintenanceWindows::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->travelTo('2026-11-11 12:00:00');
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')
        ->allowing(McpArea::Health, McpLevel::Write)->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->window = MaintenanceWindow::factory()->of($this->project)->planned()->create(['message' => 'Database upgrade']);
});

describe('listing windows', function () {
    it('returns the windows not ended and the last ended ones', function () {
        $open = MaintenanceWindow::factory()->of($this->project, 'staging')->create();
        $ended = MaintenanceWindow::factory()->of($this->project)->ended()->create();
        MaintenanceWindow::factory()->ended()->create();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'list_maintenance_windows')->assertOk());

        expect(array_column($result['windows'], 'id'))->toBe([$this->window->uuid, $open->uuid, $ended->uuid])
            ->and($result['windows'][0])->toBe($this->window->refresh()->toSummary());
    });

    it('filters by state and environment', function () {
        $open = MaintenanceWindow::factory()->of($this->project, 'staging')->create();
        $everywhere = MaintenanceWindow::factory()->of($this->project, '')->overrun()->create();

        expect(array_column($this->toolResult($this->callTool('lj_mcp_secret', 'list_maintenance_windows', ['state' => 'open']))['windows'], 'id'))->toBe([$open->uuid, $everywhere->uuid])
            ->and(array_column($this->toolResult($this->callTool('lj_mcp_secret', 'list_maintenance_windows', ['state' => 'planned']))['windows'], 'id'))->toBe([$this->window->uuid])
            ->and(array_column($this->toolResult($this->callTool('lj_mcp_secret', 'list_maintenance_windows', ['environment' => 'production']))['windows'], 'id'))->toBe([$this->window->uuid, $everywhere->uuid]);
    });
});

describe('planning and changing windows', function () {
    it('plans a window', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'create_maintenance_window', [
            'environment' => 'production', 'starts_at' => '2026-11-12T08:00:00+00:00', 'ends_at' => '2026-11-12T09:30:00+00:00', 'message' => 'Deploy',
        ])->assertOk());

        $window = MaintenanceWindow::query()->where('uuid', $result['window']['id'])->firstOrFail();
        expect($window->project_id)->toBe($this->project->id)
            ->and($window->source)->toBe(MaintenanceSource::Planned)
            ->and($window->environment)->toBe('production')
            ->and($window->expected_end_at->toIso8601String())->toBe('2026-11-12T09:30:00+00:00')
            ->and($result['window']['state'])->toBe('planned');
    });

    it('plans a window for every environment when none is given', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'create_maintenance_window', ['starts_at' => '2026-11-12T08:00:00Z', 'ends_at' => '2026-11-12T09:00:00Z'])->assertOk());

        expect($result['window']['environment'])->toBeNull()
            ->and($result['window']['message'])->toBeNull();
    });

    it('rejects an end before the start', function () {
        $response = $this->callTool('lj_mcp_secret', 'create_maintenance_window', ['starts_at' => '2026-11-12T08:00:00Z', 'ends_at' => '2026-11-12T07:00:00Z']);

        expect($this->wasRefused($response))->toBeTrue()
            ->and($this->project->maintenanceWindows()->count())->toBe(1);
    });

    it('changes only what is passed', function () {
        $this->callTool('lj_mcp_secret', 'update_maintenance_window', ['window' => $this->window->uuid, 'ends_at' => '2026-11-11T15:00:00Z'])->assertOk();

        $this->window->refresh();
        expect($this->window->expected_end_at->toIso8601String())->toBe('2026-11-11T15:00:00+00:00')
            ->and($this->window->starts_at->toIso8601String())->toBe('2026-11-11T13:00:00+00:00')
            ->and($this->window->environment)->toBe('production')
            ->and($this->window->message)->toBe('Database upgrade');
    });

    it('rejects a change that ends the window before it starts', function () {
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'update_maintenance_window', ['window' => $this->window->uuid, 'ends_at' => '2026-11-11T12:30:00Z'])))->toBeTrue()
            ->and($this->window->refresh()->expected_end_at->toIso8601String())->toBe('2026-11-11T14:00:00+00:00');
    });

    it('ends a window now', function () {
        $open = MaintenanceWindow::factory()->of($this->project)->create();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'end_maintenance_window', ['window' => $open->uuid])->assertOk());

        expect($result['window']['state'])->toBe('ended')
            ->and($open->refresh()->ended_at?->toIso8601String())->toBe('2026-11-11T12:00:00+00:00');
    });
});

describe('what the tools refuse', function () {
    dataset('changing tools', [
        'create' => ['create_maintenance_window', fn (): array => ['starts_at' => '2026-11-12T08:00:00Z', 'ends_at' => '2026-11-12T09:00:00Z']],
        'update' => ['update_maintenance_window', fn (): array => ['window' => $this->window->uuid, 'message' => 'Changed']],
        'end' => ['end_maintenance_window', fn (): array => ['window' => $this->window->uuid]],
    ]);

    it('refuses a read-only token', function (string $tool, Closure $arguments) {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)]);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments->call($this))))->toBeTrue()
            ->and($this->project->maintenanceWindows()->count())->toBe(1)
            ->and($this->window->refresh()->message)->toBe('Database upgrade')
            ->and($this->window->ended_at)->toBeNull();
    })->with('changing tools');

    it('refuses when the maker may not change the project', function (string $tool, Closure $arguments) {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments->call($this))))->toBeTrue()
            ->and($this->project->maintenanceWindows()->count())->toBe(1)
            ->and($this->window->refresh()->message)->toBe('Database upgrade')
            ->and($this->window->ended_at)->toBeNull();
    })->with('changing tools');

    it('refuses a window of another team\'s project', function () {
        $other = MaintenanceWindow::factory()->create();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'end_maintenance_window', ['window' => $other->uuid])))->toBeTrue()
            ->and($other->refresh()->ended_at)->toBeNull();
    });
});
