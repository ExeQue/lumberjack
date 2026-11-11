<?php

declare(strict_types=1);

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Actions\CreateProjectAction;
use App\Domains\Projects\Http\Controllers\ProjectSettingsController;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\SourceMaps\Models\SourceMap;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Shared\ReadsQueryCounts;
use Tests\Support\Teams\CreatesTeamMembers;

covers(ProjectSettingsController::class);

uses(CreatesTeamMembers::class, ReadsQueryCounts::class);

describe('project administration', function () {
    it('is closed to members', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Member);

        $this->actingAs($user)->get(route('projects.settings', $project))->assertForbidden();
        $this->actingAs($user)->post(route('projects.rotate-token', $project))->assertForbidden();
    });

    it('rotates the server token and hands it out once', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Admin);
        $old = $project->token_hash;

        $this->actingAs($user)->post(route('projects.rotate-token', $project))->assertSessionHas('secret');

        expect($project->refresh()->token_hash)->not->toBe($old);
    });

    it('saves name, slug and allowed origins', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();

        $this->actingAs($user)->put(route('projects.settings.update', $project), [
            'name' => 'Shop', 'slug' => 'shop', 'allowed_origins' => ['https://shop.test/'],
        ])->assertSessionHasNoErrors();

        expect($project->refresh()->allowed_origins)->toBe(['https://shop.test']);
    });

    it('lets the issue prefix be chosen, unique in the team', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $other = Project::factory()->create(['team_id' => $project->team_id, 'issue_prefix' => 'API']);
        $save = fn (string $prefix) => $this->actingAs($user)->put(route('projects.settings.update', $project), [
            'name' => $project->name, 'slug' => $project->slug, 'allowed_origins' => [], 'issue_prefix' => $prefix,
        ]);

        $save('shop')->assertSessionHasNoErrors();
        expect($project->refresh()->issuePrefix())->toBe('SHOP');

        $save('API')->assertSessionHasErrors('issue_prefix');
        $save('1SHOP')->assertSessionHasErrors('issue_prefix');
        $save('S')->assertSessionHasErrors('issue_prefix');
        expect($project->refresh()->issuePrefix())->toBe('SHOP')
            ->and($other->refresh()->issuePrefix())->toBe('API');
    });

    it('gives new projects the initials of their name as prefix, unique in the team', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();

        $first = app(CreateProjectAction::class)->execute($project->team, 'Eazy Photo')['project'];
        $second = app(CreateProjectAction::class)->execute($project->team, 'Eazy Photographers')['project'];

        expect($first->issuePrefix())->toBe('EP')
            ->and($second->issuePrefix())->toBe('EP2');
    });
});

describe('log channels', function () {
    it('creates a kebab-case channel', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();

        $this->actingAs($user)->post(route('log-channels.store', $project), ['name' => 'example-log-channel'])->assertSessionHasNoErrors();

        expect($project->logChannels()->pluck('name'))->toContain('example-log-channel');
    });

    it('refuses names that are not kebab-case, taken or reserved', function (string $name) {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $project->logChannels()->create(['name' => 'payments']);

        $this->actingAs($user)->post(route('log-channels.store', $project), ['name' => $name])->assertSessionHasErrors('name');
    })->with([
        'upper case and spaces' => ['Mailchimp Sync'],
        'a trailing hyphen' => ['payments-'],
        'the reserved default' => ['default'],
        'an existing channel' => ['payments'],
    ]);

    it('never deletes the default channel', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $default = $project->logChannels()->where('name', LogChannel::DEFAULT)->sole();

        $this->actingAs($user)->delete(route('log-channels.destroy', [$project, $default]))->assertSessionHasErrors('channel');

        expect($default->fresh())->not->toBeNull();
    });
});

describe('agents', function () {
    it('shows what each agent\'s host reported last, with its memory over the day', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Owner);
        $agent = ProjectAgent::factory()->for($project)->create();
        HostSample::query()->create(['project_agent_id' => $agent->id, 'memory_used_bytes' => 50, 'memory_total_bytes' => 100, 'cpu_load_1m' => 3.0, 'sampled_at' => now()->subHours(2)]);
        HostSample::query()->create(['project_agent_id' => $agent->id, 'memory_used_bytes' => 95, 'memory_total_bytes' => 100, 'cpu_load_1m' => 0.5, 'disk_used_bytes' => 1, 'disk_total_bytes' => 4, 'sampled_at' => now()]);

        $this->actingAs($user)->get(route('projects.settings', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('agents.0.metrics.memory_used_bytes', 95)
                ->where('agents.0.metrics.disk_total_bytes', 4)
                ->where('agents.0.metrics.cpu_load_1m', 0.5)
                ->where('agents.0.metrics.memory_series', fn ($series) => max($series->all()) === 0.95 && in_array(0.5, $series->all(), true))
                ->where('agents.0.metrics.load_series', fn ($series) => max($series->all()) == 3));
    });

    it('reads the series of every agent in the same few queries', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Owner);
        $page = fn () => $this->actingAs($user)->get(route('projects.settings', $project))->assertOk();
        HostSample::factory()->for(ProjectAgent::factory()->for($project)->onHost('web-0'), 'agent')->create();
        $one = $this->queriesRunBy($page);

        foreach (['web-1', 'web-2', 'web-3'] as $host) {
            HostSample::factory()->for(ProjectAgent::factory()->for($project)->onHost($host), 'agent')->create();
        }

        expect($this->queriesRunBy($page))->toBe($one);
        $page()->assertInertia(fn (Assert $page) => $page->where('agents.3.metrics.load_series', fn ($series) => max($series->all()) == 0.8));
    });
});

describe('source maps', function () {
    it('lists the project\'s uploads, newest first', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Admin);
        SourceMap::factory()->for($project)->create(['path' => '/build/assets/old.js', 'updated_at' => now()->subDay()]);
        SourceMap::factory()->for($project)->create(['path' => '/build/assets/new.js', 'release' => 'v2', 'size' => 2048]);
        SourceMap::factory()->create(['path' => '/build/assets/elsewhere.js']);

        $this->actingAs($user)->get(route('projects.settings', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('source_maps', 2)
                ->where('source_maps.0.path', '/build/assets/new.js')
                ->where('source_maps.0.release', 'v2')
                ->where('source_maps.0.size', 2048)
                ->where('source_maps.1.path', '/build/assets/old.js')
                ->where('source_maps_total', 2)
                ->where('settings.sourcemaps_url', url('/api/ingest/sourcemaps')));
    });
});

describe('public stats settings', function () {
    it('turns public stats on and shows their URL, for admins only', function () {
        ['user' => $member, 'project' => $project] = $this->memberWithProject(TeamRole::Member);
        $admin = User::factory()->inTeam($project->team, TeamRole::Admin)->create();

        $this->actingAs($member)->put(route('projects.public-stats', $project), ['enabled' => true])->assertForbidden();
        $this->actingAs($admin)->put(route('projects.public-stats', $project), ['enabled' => true, 'include_titles' => true])->assertSessionHasNoErrors();

        expect($project->refresh()->stats_key)->not->toBeNull()->and($project->stats_include_titles)->toBeTrue();

        $this->actingAs($admin)->get(route('projects.settings', $project))
            ->assertInertia(fn (Assert $page) => $page->where('settings.stats_url', url('/api/v1/public/'.$project->stats_key)));
    });

    it('rotates the key so the old URL stops working', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Admin);
        $project->update(['stats_key' => 'ps_'.str_repeat('a', 40)]);

        $this->actingAs($user)->post(route('projects.rotate-stats-key', $project))->assertSessionHas('status');

        expect($project->refresh()->stats_key)->not->toBe('ps_'.str_repeat('a', 40));
    });
});
