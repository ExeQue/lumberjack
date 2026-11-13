<?php

declare(strict_types=1);

use App\Domains\Connectors\Enums\Connector;
use App\Domains\Connectors\Enums\ConnectorTag;
use App\Domains\Connectors\Http\Controllers\ConnectorController;
use App\Domains\Connectors\Presenters\ConnectorPresenter;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(ConnectorController::class, ConnectorPresenter::class, Connector::class, ConnectorTag::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
});

describe('the catalog', function () {
    it('lists every connector by name with its tags when none is connected', function () {
        $this->actingAs($this->admin)->get(route('connectors.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connectors/index')
                ->where('connectors', fn ($rows): bool => collect($rows)->pluck('key')->all() === ['github', 'mosparo', 'packagist', 'umami', 'mobilepay'])
                ->where('connectors.0.label', 'GitHub')
                ->where('connectors.0.description', __('connectors.catalog.github.description'))
                ->where('connectors.0.tags', ['development', 'security'])
                ->where('connectors.0.url', route('connectors.github.index', $this->project))
                ->where('connectors.0.connections', [])
                ->where('tags', fn ($rows): bool => collect($rows)->pluck('key')->all() === ['analytics', 'commerce', 'development', 'forms', 'payments', 'security'])
                ->where('tags.0.label', 'Analytics'));
    });

    it('puts the connected ones first, each group by name, with links to their connections', function () {
        $ledger = DataSourceConnection::factory()->for($this->project)->mobilePay()->create(['name' => 'Oslo']);
        DataSourceConnection::factory()->for($this->project)->umami()->create(['name' => 'Shop']);
        $blog = DataSourceConnection::factory()->for($this->project)->umami()->create(['name' => 'Blog', 'environment' => 'umami-blog']);
        // Another project's, and an app's, are not counted.
        DataSourceConnection::factory()->github()->create();
        DataSourceConnection::factory()->for($this->project)->create();

        $this->actingAs($this->member)->get(route('connectors.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('connectors', fn ($rows): bool => collect($rows)->pluck('key')->all() === ['umami', 'mobilepay', 'github', 'mosparo', 'packagist'])
                ->where('connectors.0.connections', fn ($rows): bool => collect($rows)->pluck('name')->all() === ['Blog', 'Shop'])
                ->where('connectors.0.connections.0', [
                    'uuid' => $blog->uuid,
                    'name' => 'Blog',
                    'url' => route('connectors.umami.index', [$this->project, $blog]),
                    'environment' => 'umami-blog',
                    'last_ok_at' => null,
                    'last_checked_at' => null,
                    'last_error' => null,
                ])
                ->where('connectors.1.connections.0.url', route('connectors.mobilepay.index', [$this->project, $ledger]))
                ->where('connectors.2.connections', []));
    });

    it('tells how each connection\'s checks went', function () {
        $this->freezeSecond();
        DataSourceConnection::factory()->for($this->project)->github()->create([
            'name' => 'acme/shop',
            'last_checked_at' => now(),
            'last_ok_at' => now()->subHour(),
            'last_error' => 'Bad credentials',
        ]);
        DataSourceConnection::factory()->for($this->project)->github()->create([
            'name' => 'acme/api',
            'last_checked_at' => now(),
            'last_ok_at' => now(),
        ]);

        $this->actingAs($this->member)->get(route('connectors.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connectors.0.connections', fn ($rows): bool => collect($rows)->pluck('name')->all() === ['acme/api', 'acme/shop'])
                ->where('connectors.0.connections.0.last_ok_at', now()->toIso8601String())
                ->where('connectors.0.connections.0.last_checked_at', now()->toIso8601String())
                ->where('connectors.0.connections.0.last_error', null)
                ->where('connectors.0.connections.1.last_ok_at', now()->subHour()->toIso8601String())
                ->where('connectors.0.connections.1.last_error', 'Bad credentials'));
    });

    it('answers for the tags of every connector', function (Connector $connector) {
        expect($connector->tags())->not->toBeEmpty()->each->toBeInstanceOf(ConnectorTag::class);
    })->with(Connector::cases());
});

describe('who may connect', function () {
    it('lets owners and admins connect', function () {
        $this->actingAs($this->admin)->get(route('connectors.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('project.can_administer', true));
    });

    it('shows members the page read only', function () {
        $this->actingAs($this->member)->get(route('connectors.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('project.can_administer', false));
    });

    it('refuses someone outside the team', function () {
        $this->actingAs(User::factory()->create())->get(route('connectors.index', $this->project))
            ->assertForbidden();
    });
});
