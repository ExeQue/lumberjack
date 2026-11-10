<?php

declare(strict_types=1);

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Projects\CreateLogChannel;
use App\Domains\Mcp\Tools\Projects\DeleteLogChannel;
use App\Domains\Mcp\Tools\Projects\GetProjectSettings;
use App\Domains\Mcp\Tools\Projects\SetProjectRetention;
use App\Domains\Mcp\Tools\Projects\SetPublicStats;
use App\Domains\Mcp\Tools\Projects\UpdateProject;
use App\Domains\Projects\Models\Project;
use App\Domains\Repositories\Models\ProjectRepository;
use App\Domains\Retention\Actions\SaveProjectRetentionAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Mcp\CallsMcpTools;

covers(GetProjectSettings::class, UpdateProject::class, CreateLogChannel::class, DeleteLogChannel::class, SetProjectRetention::class, SaveProjectRetentionAction::class, SetPublicStats::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop', 'name' => 'Shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')
        ->allowing(McpArea::Project, McpLevel::Manage)->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
});

describe('reading settings', function () {
    it('returns the settings without any secret', function () {
        ProjectRepository::factory()->for($this->project)->create(['repository' => 'acme/shop', 'token' => 'ghp_secret_token']);
        $this->project->update(['stats_key' => 'stats_secret_key']);

        $response = $this->callTool('lj_mcp_secret', 'get_project_settings')->assertOk();
        $result = $this->toolResult($response);

        expect($result)->toMatchArray(['name' => 'Shop', 'slug' => 'shop', 'public_stats' => ['enabled' => true, 'include_titles' => false]])
            ->and($result['repository']['repository'])->toBe('acme/shop')
            ->and($result['log_channels'][0]['name'])->toBe(LogChannel::DEFAULT)
            ->and($response->getContent())->not->toContain('ghp_secret_token', 'stats_secret_key', $this->project->public_key);
    });
});

describe('changing the project', function () {
    it('changes only what is passed', function () {
        $this->project->update(['allowed_origins' => ['https://shop.example.com'], 'scrub_keys' => ['card_number']]);

        // Origins and scrubbed keys decide what data is kept, so they stay on the settings page.
        $this->callTool('lj_mcp_secret', 'update_project', ['name' => 'Web shop', 'issue_prefix' => 'web', 'scrub_keys' => []])->assertOk();

        expect($this->project->refresh())
            ->name->toBe('Web shop')
            ->slug->toBe('shop')
            ->issue_prefix->toBe('WEB')
            ->allowed_origins->toBe(['https://shop.example.com'])
            ->scrub_keys->toBe(['card_number']);
    });

    it('creates and deletes a log channel, but not the default one', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'create_log_channel', ['name' => 'payments'])->assertOk());
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'create_log_channel', ['name' => 'Not Kebab'])))->toBeTrue();

        $this->callTool('lj_mcp_secret', 'delete_log_channel', ['channel_id' => $result['id']])->assertOk();
        $default = $this->project->logChannels()->where('name', LogChannel::DEFAULT)->firstOrFail();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_log_channel', ['channel_id' => $default->id])))->toBeTrue()
            ->and($this->project->logChannels()->pluck('name')->all())->toBe([LogChannel::DEFAULT]);
    });

    it('turns public stats on without returning the key', function () {
        $response = $this->callTool('lj_mcp_secret', 'set_public_stats', ['enabled' => true])->assertOk();

        expect($this->toolResult($response))->toBe(['enabled' => true, 'include_titles' => false])
            ->and($this->project->refresh()->stats_key)->not->toBeNull()
            ->and($response->getContent())->not->toContain($this->project->stats_key);
    });
});

describe('retention', function () {
    it('changes the named data types and keeps the others', function () {
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::Logs, 'days' => 30]);

        $this->callTool('lj_mcp_secret', 'set_project_retention', ['days' => ['requests' => 14]])->assertOk();

        expect(RetentionPolicy::query()->whereProject($this->project->id)->get()->mapWithKeys(fn (RetentionPolicy $p): array => [$p->category->value => $p->days])->all())
            ->toEqual(['logs' => 30, 'requests' => 14]);
    });

    it('refuses what the settings page refuses, such as days above the team\'s cap', function () {
        RetentionPolicy::factory()->create(['team_id' => $this->project->team_id, 'category' => RetentionCategory::Requests, 'days' => 7]);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'set_project_retention', ['days' => ['requests' => 14]])))->toBeTrue()
            ->and(RetentionPolicy::query()->whereProject($this->project->id)->count())->toBe(0);
    });
});

describe('what it refuses', function () {
    it('refuses a token below the tool\'s level', function (string $tool, array $arguments) {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)]);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments)))->toBeTrue()
            ->and($this->project->refresh()->name)->toBe('Shop')
            ->and($this->project->stats_key)->toBeNull();
    })->with([
        'update_project' => ['update_project', ['name' => 'Renamed']],
        'create_log_channel' => ['create_log_channel', ['name' => 'payments']],
        'set_public_stats' => ['set_public_stats', ['enabled' => true]],
        'set_project_retention' => ['set_project_retention', ['days' => ['requests' => 14]]],
    ]);

    it('refuses when the maker may not change the project', function (string $tool, array $arguments) {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments)))->toBeTrue()
            ->and($this->project->refresh()->name)->toBe('Shop')
            ->and($this->project->stats_key)->toBeNull()
            ->and($this->project->logChannels()->count())->toBe(1)
            ->and(RetentionPolicy::query()->whereProject($this->project->id)->count())->toBe(0);
    })->with([
        'get_project_settings' => ['get_project_settings', []],
        'update_project' => ['update_project', ['name' => 'Renamed']],
        'create_log_channel' => ['create_log_channel', ['name' => 'payments']],
        'set_public_stats' => ['set_public_stats', ['enabled' => true]],
        'set_project_retention' => ['set_project_retention', ['days' => ['requests' => 14]]],
    ]);

    it('keeps a log channel the maker may not delete', function () {
        $channel = LogChannel::factory()->for($this->project)->create();
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_log_channel', ['channel_id' => $channel->id])))->toBeTrue()
            ->and(LogChannel::query()->find($channel->id))->not->toBeNull();
    });
});
