<?php

declare(strict_types=1);

use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckResult;
use App\Domains\Mcp\Data\McpAreaGrant;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Concerns\UsesHealthChecks;
use App\Domains\Mcp\Tools\Health\ArchiveHealthCheck;
use App\Domains\Mcp\Tools\Health\CreateHealthCheck;
use App\Domains\Mcp\Tools\Health\DeleteHealthCheck;
use App\Domains\Mcp\Tools\Health\GetHealthCheck;
use App\Domains\Mcp\Tools\Health\RunHealthCheck;
use App\Domains\Mcp\Tools\Health\UpdateHealthCheck;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\Support\Health\FakesHealthProbes;
use Tests\Support\Mcp\CallsMcpTools;

covers(GetHealthCheck::class, CreateHealthCheck::class, UpdateHealthCheck::class, RunHealthCheck::class, ArchiveHealthCheck::class, DeleteHealthCheck::class, UsesHealthChecks::class);

uses(CallsMcpTools::class, FakesHealthProbes::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')
        ->allowing(McpArea::Health, McpLevel::Manage)->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->check = HealthCheck::factory()->for($this->project)->monitored(HealthCheckType::Http, ['url' => 'https://user:pass@example.com/up'])->create();
    $this->heartbeat = HealthCheck::factory()->for($this->project)->monitored(HealthCheckType::Heartbeat, ['grace_minutes' => 5])->create(['name' => 'Backup']);
});

describe('reading a check', function () {
    it('returns settings and results without credentials or the ping URL', function () {
        HealthCheckResult::factory()->of($this->check)->create(['message' => '200 in 4 ms.']);

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'get_health_check', ['check' => $this->check->uuid])->assertOk());

        expect($result['check']['config']['url'])->toBe('https://***@example.com/up')
            ->and($result['results'][0]['message'])->toBe('200 in 4 ms.');

        $heartbeat = $this->callTool('lj_mcp_secret', 'get_health_check', ['check' => $this->heartbeat->uuid])->assertOk();
        expect($heartbeat->getContent())->not->toContain($this->heartbeat->ping_token);
    });

    it('refuses a check of another project', function () {
        $other = HealthCheck::factory()->monitored()->create();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'get_health_check', ['check' => $other->uuid])))->toBeTrue();
    });
});

describe('creating and updating checks', function () {
    it('adds a check with the web form\'s rules and defaults', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'create_health_check', [
            'type' => 'tcp', 'interval_minutes' => 5, 'config' => ['host' => 'db.example.com', 'port' => 5432],
        ])->assertOk());

        $check = HealthCheck::query()->where('uuid', $result['check']['id'])->firstOrFail();
        expect($check->type)->toBe(HealthCheckType::Tcp)
            ->and($check->config)->toBe(['host' => 'db.example.com', 'port' => 5432])
            ->and($check->degrade_after)->toBe(HealthCheck::DEFAULT_DEGRADE_AFTER);
    });

    it('rejects what the web form rejects', function () {
        $response = $this->callTool('lj_mcp_secret', 'create_health_check', ['type' => 'tcp', 'interval_minutes' => 5, 'config' => ['host' => 'db.example.com']]);

        expect($this->wasRefused($response))->toBeTrue()
            ->and($this->project->healthChecks()->count())->toBe(2);
    });

    it('changes only what is passed', function () {
        $this->callTool('lj_mcp_secret', 'update_health_check', ['check' => $this->check->uuid, 'muted' => true, 'config' => ['expected_status' => 204]])->assertOk();

        $this->check->refresh();
        expect($this->check->muted)->toBeTrue()
            ->and($this->check->name)->toBe('Website')
            ->and($this->check->config)->toBe(['url' => 'https://user:pass@example.com/up', 'expected_status' => 204]);
    });

    it('keeps the stored url when the agent sends back the one it was shown', function () {
        $shown = $this->toolResult($this->callTool('lj_mcp_secret', 'get_health_check', ['check' => $this->check->uuid]))['check']['config'];

        $this->callTool('lj_mcp_secret', 'update_health_check', ['check' => $this->check->uuid, 'config' => [...$shown, 'timeout_s' => 12]])->assertOk();

        expect($this->check->refresh()->config['url'])->toBe('https://user:pass@example.com/up');
    });

    it('refuses a changed url that still carries a masked value', function () {
        $this->check->update(['config' => ['url' => 'https://example.com/up?token=s3cr3t']]);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'update_health_check', ['check' => $this->check->uuid, 'config' => ['url' => 'https://example.com/health?token=***']])))->toBeTrue()
            ->and($this->check->refresh()->config['url'])->toBe('https://example.com/up?token=s3cr3t');
    });
});

describe('running a check', function () {
    it('runs it now and records the result', function () {
        config(['health.allow_private' => false]);
        $this->fakeDns();
        Http::fake(['https://user:pass@example.com/up' => Http::response('ok'), '*' => Http::response('ok')]);

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'run_health_check', ['check' => $this->check->uuid])->assertOk());

        expect($result['result']['status'])->toBe('ok')
            ->and($this->check->results()->count())->toBe(1);
    });

    it('refuses a check the app reports', function () {
        $reported = HealthCheck::factory()->for($this->project)->reported('database:pgsql')->create();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'run_health_check', ['check' => $reported->uuid])))->toBeTrue();
    });
});

describe('archiving and deleting', function () {
    it('archives, unarchives and deletes a check', function () {
        $this->callTool('lj_mcp_secret', 'archive_health_check', ['check' => $this->check->uuid, 'archived' => true])->assertOk();
        expect($this->check->refresh()->archived_at)->not->toBeNull();

        $this->callTool('lj_mcp_secret', 'archive_health_check', ['check' => $this->check->uuid, 'archived' => false])->assertOk();
        expect($this->check->refresh()->archived_at)->toBeNull();

        $this->callTool('lj_mcp_secret', 'delete_health_check', ['check' => $this->check->uuid])->assertOk();
        expect(HealthCheck::query()->whereKey($this->check->id)->exists())->toBeFalse();
    });
});

describe('what the tools refuse', function () {
    dataset('changing tools', [
        'create' => ['create_health_check', fn (): array => ['type' => 'tcp', 'interval_minutes' => 5, 'config' => ['host' => 'db.example.com', 'port' => 5432]]],
        'update' => ['update_health_check', fn (): array => ['check' => $this->check->uuid, 'muted' => true]],
        'run' => ['run_health_check', fn (): array => ['check' => $this->check->uuid]],
        'archive' => ['archive_health_check', fn (): array => ['check' => $this->check->uuid, 'archived' => true]],
        'delete' => ['delete_health_check', fn (): array => ['check' => $this->check->uuid]],
    ]);

    it('refuses a read-only token', function (string $tool, Closure $arguments) {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)]);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments->call($this))))->toBeTrue()
            ->and($this->check->refresh()->muted)->toBeFalse()
            ->and($this->check->archived_at)->toBeNull();
    })->with('changing tools');

    it('refuses when the maker may not change the project', function (string $tool, Closure $arguments) {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments->call($this))))->toBeTrue()
            ->and($this->project->healthChecks()->count())->toBe(2)
            ->and($this->check->refresh()->muted)->toBeFalse();
    })->with('changing tools');

    it('refuses deleting at write level', function () {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)->with(McpArea::Health, new McpAreaGrant(McpLevel::Write))]);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_health_check', ['check' => $this->check->uuid])))->toBeTrue()
            ->and(HealthCheck::query()->whereKey($this->check->id)->exists())->toBeTrue();
    });
});
