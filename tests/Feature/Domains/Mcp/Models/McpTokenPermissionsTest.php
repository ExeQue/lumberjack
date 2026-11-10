<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Data\McpAreaGrant;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Support\ScreenAccess;
use App\Domains\Metrics\Support\MetricsCredential;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Models\Screen;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Mcp\CallsMcpTools;

covers(McpToken::class, ScreenAccess::class, MetricsCredential::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->shop = Project::factory()->create(['slug' => 'shop']);
    $this->blog = Project::factory()->for($this->shop->team)->create(['slug' => 'blog']);
    $this->user = User::factory()->inTeam($this->shop->team)->create();
    $this->token = McpToken::factory()->for($this->shop->team)->withToken('lj_mcp_secret')->readOnly()->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach([$this->shop->id, $this->blog->id]);
    $this->change = fn (Project $project, array $permissions) => $this->token->projects()->updateExistingPivot($project->id, ['permissions' => McpPermissions::fromArray($permissions)]);
    $this->tools = fn (): array => array_column($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer lj_mcp_secret', 'Accept' => 'application/json, text/event-stream'])->json('result.tools'), 'name');
});

describe('which tool a token may use', function () {
    it('follows the level of the tool\'s area', function () {
        expect($this->token->permits('get_issue', $this->shop))->toBeTrue()
            ->and($this->token->permits('set_issue_status', $this->shop))->toBeFalse()
            ->and($this->token->permits('list_projects'))->toBeTrue()
            ->and($this->token->permits('no_such_tool'))->toBeFalse();
    });

    it('lets a single tool be added beyond the level or taken away from it', function () {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)->with(McpArea::Issues, new McpAreaGrant(McpLevel::Read, grant: ['add_issue_note'], deny: ['list_occurrences']))]);

        expect($this->token->permits('add_issue_note', $this->shop))->toBeTrue()
            ->and($this->token->permits('set_issue_status', $this->shop))->toBeFalse()
            ->and($this->token->permits('list_occurrences', $this->shop))->toBeFalse()
            ->and($this->token->permits('get_issue', $this->shop))->toBeTrue();
    });

    it('lets one project change Default, a tool change winning over its level', function () {
        ($this->change)($this->shop, ['issues' => ['level' => 'write', 'deny' => ['create_repo_issue']]]);

        $token = $this->token->fresh();

        expect($token->permits('set_issue_status', $this->shop))->toBeTrue()
            ->and($token->permits('create_repo_issue', $this->shop))->toBeFalse()
            ->and($token->permits('set_issue_status', $this->blog))->toBeFalse();
    });

    it('keeps Default\'s tool changes for a project that only changes other areas', function () {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)->with(McpArea::Telemetry, new McpAreaGrant(McpLevel::Read, deny: ['search_logs']))]);
        ($this->change)($this->shop, ['health' => ['level' => 'write']]);

        expect($this->token->fresh()->permits('search_logs', $this->shop))->toBeFalse();
    });

    it('reads team-wide areas from Default only', function () {
        ($this->change)($this->shop, ['screens' => ['level' => 'write']]);

        expect($this->token->fresh()->permits('update_screen', $this->shop))->toBeFalse();
    });
});

describe('what an agent sees', function () {
    it('lists only tools the token may use somewhere', function () {
        expect(($this->tools)())->toContain('get_issue', 'list_my_permissions')->not->toContain('set_issue_status', 'update_screen');

        ($this->change)($this->blog, ['issues' => ['level' => 'write']]);

        expect(($this->tools)())->toContain('set_issue_status');
    });

    it('refuses a tool in a project that does not allow it, and names the project', function () {
        ($this->change)($this->blog, ['issues' => ['level' => 'write']]);
        $issue = Issue::factory()->for($this->shop)->create();

        $response = $this->callTool('lj_mcp_secret', 'set_issue_status', ['issue' => $issue->reference(), 'status' => 'resolved']);

        expect($response->json('result.isError'))->toBeTrue()
            ->and($response->json('result.content.0.text'))->toContain('project shop')
            ->and($issue->refresh()->status->value)->toBe('open');
    });

    it('still asks its maker\'s policies when the token allows the change', function () {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Manage)]);
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);
        $issue = Issue::factory()->for($this->shop)->create();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'set_issue_status', ['issue' => $issue->reference(), 'status' => 'resolved'])))->toBeTrue()
            ->and($issue->refresh()->status->value)->toBe('open');
    });

    it('tells the agent what it may do where', function () {
        ($this->change)($this->blog, ['issues' => ['level' => 'write']]);

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'list_my_permissions'));

        expect($result['levels']['issues'])->toBe('read')
            ->and(collect($result['projects'])->firstWhere('slug', 'blog')['tools'])->toContain('set_issue_status')
            ->and(collect($result['projects'])->firstWhere('slug', 'shop')['tools'])->not->toContain('set_issue_status');
    });
});

describe('a token covering every project', function () {
    it('covers projects made after it', function () {
        $this->token->update(['all_projects' => true]);
        $later = Project::factory()->for($this->shop->team)->create(['slug' => 'later']);

        expect($this->token->fresh()->readableProjects()->pluck('slug')->all())->toContain('later')
            ->and($this->token->fresh()->permits('get_issue', $later))->toBeTrue();
    });
});

describe('areas that show other areas\' data', function () {
    it('hides issues from project search where the token may not read issues', function () {
        Issue::factory()->for($this->shop)->create(['title' => 'Checkout timeout']);

        $results = fn (): array => array_column($this->toolResult($this->callTool('lj_mcp_secret', 'search_project', ['project' => 'shop', 'q' => 'Checkout']))['results'] ?? [], 'group');
        expect($results())->toContain('issues');

        ($this->change)($this->shop, ['issues' => ['level' => 'none']]);
        expect($results())->not->toContain('issues');
    });

    it('shows a screen only when the token reads telemetry and issues in every project on it', function () {
        $screen = Screen::factory()->for($this->shop->team)->create(['scope' => ['projects' => [$this->shop->uuid]]]);
        $read = fn (): bool => $this->wasRefused($this->callTool('lj_mcp_secret', 'get_screen', ['screen' => $screen->uuid]));

        expect($read())->toBeFalse();

        ($this->change)($this->shop, ['issues' => ['level' => 'none']]);
        expect($read())->toBeTrue();
    });

    it('shows another area\'s widgets only where the token reads that area', function (array $widget, string $area) {
        $screen = Screen::factory()->for($this->shop->team)->create([
            'scope' => ['projects' => [$this->shop->uuid]],
            'widgets' => [['id' => 'w', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'scope' => ['projects' => [$this->shop->uuid]], 'options' => [], ...$widget]],
        ]);
        $read = fn (): bool => $this->wasRefused($this->callTool('lj_mcp_secret', 'get_screen', ['screen' => $screen->uuid]));

        expect($read())->toBeFalse();

        $area === 'alerts'
            ? $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)->with(McpArea::Alerts, new McpAreaGrant(McpLevel::None))])
            : ($this->change)($this->shop, [$area => ['level' => 'none']]);

        expect($read())->toBeTrue();
    })->with([
        'every health check, none picked' => [['type' => 'health', 'source' => 'checks'], 'health'],
        'uptime of every check' => [['type' => 'gauge', 'source' => 'health_uptime'], 'health'],
        'the alert feed' => [['type' => 'list', 'source' => 'alert_feed'], 'alerts'],
        'scheduled tasks' => [['type' => 'list', 'source' => 'scheduled_tasks'], 'schedule'],
    ]);

    it('shows a screen with a health check only where the token reads health checks', function () {
        $check = HealthCheck::factory()->for($this->shop)->create();
        $screen = Screen::factory()->for($this->shop->team)->create([
            'scope' => ['projects' => [$this->shop->uuid]],
            'widgets' => [['id' => 'h', 'type' => 'stat', 'source' => 'error_rate', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 2, 'scope' => ['projects' => [$this->shop->uuid]], 'options' => ['checks' => [$check->uuid]]]],
        ]);
        $read = fn (): bool => $this->wasRefused($this->callTool('lj_mcp_secret', 'get_screen', ['screen' => $screen->uuid]));

        expect($read())->toBeFalse();

        ($this->change)($this->shop, ['health' => ['level' => 'none']]);
        expect($read())->toBeTrue();
    });
});

describe('the metrics API', function () {
    it('lists issues only where the token may read them', function () {
        $this->withToken('lj_mcp_secret')->getJson('/api/v1/projects/shop/issues')->assertOk();

        ($this->change)($this->shop, ['issues' => ['level' => 'none']]);

        $this->withToken('lj_mcp_secret')->getJson('/api/v1/projects/shop/issues')->assertForbidden();
    });

    it('reads only projects where the token may read metric series', function () {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)->with(McpArea::Telemetry, new McpAreaGrant(McpLevel::Read))]);
        ($this->change)($this->blog, ['telemetry' => ['deny' => ['get_metric_series']]]);

        $this->withToken('lj_mcp_secret')->getJson('/api/v1/projects')->assertOk()
            ->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.slug', 'shop');
    });
});
