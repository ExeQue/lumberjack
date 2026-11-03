<?php

declare(strict_types=1);

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Actions\CreateProjectAction;
use App\Domains\Projects\Http\Controllers\ProjectSettingsController;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Teams\Enums\TeamRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Teams\CreatesTeamMembers;

covers(ProjectSettingsController::class);

uses(CreatesTeamMembers::class);

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
});
