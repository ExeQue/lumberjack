<?php

declare(strict_types=1);

use App\Domains\Connectors\GitHub\Actions\ConnectGitHubAction;
use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Http\Controllers\GitHubConnectionController;
use App\Domains\Connectors\GitHub\Http\Controllers\GitHubController;
use App\Domains\Connectors\GitHub\Http\Requests\ConnectGitHubRequest;
use App\Domains\Connectors\GitHub\Queries\GitHubDashboardQuery;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Connectors\FakesGitHub;

covers(GitHubController::class, GitHubConnectionController::class, ConnectGitHubRequest::class, ConnectGitHubAction::class, GitHubDashboardQuery::class);

uses(FakesGitHub::class);

beforeEach(function () {
    $this->travelTo('2026-11-10 12:00:00');
    $this->fakeGitHub();
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->input = ['repository' => 'acme/shop', 'token' => 'github_pat_test'];
});

describe('connecting a repository', function () {
    it('keeps the token encrypted and opens its page', function () {
        $response = $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), $this->input)->assertSessionHasNoErrors();

        $connection = DataSourceConnection::query()->sole();

        $response->assertRedirect(route('connectors.github.index', [$this->project, $connection]));
        expect($connection->kind)->toBe(ConnectionKind::GitHub)
            ->and($connection->url)->toBe('https://github.com/acme/shop')
            ->and($connection->name)->toBe('acme/shop')
            ->and($connection->gitHubCredentials()->token)->toBe('github_pat_test')
            ->and(DB::table('data_source_connections')->value('config'))->not->toContain('github_pat_test');
    });

    it('takes the repository\'s address and renews the token of one already connected', function () {
        $this->actingAs($this->admin);
        $this->post(route('connectors.github.connection.store', $this->project), $this->input);
        $this->post(route('connectors.github.connection.store', $this->project), ['repository' => 'https://github.com/acme/shop.git', 'token' => 'renewed'])->assertSessionHasNoErrors();

        expect(DataSourceConnection::query()->count())->toBe(1)
            ->and($this->project->gitHubConnections()->sole()->gitHubCredentials()->token)->toBe('renewed');
    });

    it('takes any address under the repository', function (string $address) {
        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), [...$this->input, 'repository' => $address])
            ->assertSessionHasNoErrors();

        expect($this->project->gitHubConnections()->sole()->name)->toBe('acme/shop');
    })->with([
        'the repository itself' => ['https://github.com/acme/shop'],
        'a trailing slash' => ['https://github.com/acme/shop/'],
        'without the scheme' => ['github.com/acme/shop'],
        'a file on a branch' => ['https://github.com/acme/shop/blob/main/src/app.ts'],
        'a pull request' => ['https://github.com/acme/shop/pull/12'],
        'a query string' => ['https://github.com/acme/shop?tab=readme-ov-file'],
        'surrounding spaces' => ['  https://www.github.com/acme/shop/issues  '],
    ]);

    it('remembers the optional permissions the token has, and says which it lacks', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/issues' => [['message' => 'Resource not accessible by personal access token'], 403]];

        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), $this->input)
            ->assertSessionHas('status', __('connectors.github.connected_missing', ['name' => 'acme/shop', 'permissions' => 'Issues']));

        expect(GitHubConfig::grantedBy(DataSourceConnection::query()->sole()->config))->toBe([GitHubPermission::DependabotAlerts, GitHubPermission::CodeScanningAlerts, GitHubPermission::SecretScanningAlerts]);
    });

    it('asks for the optional permissions again when the token is renewed', function () {
        $this->actingAs($this->admin);
        $this->gitHubAnswers = ['/repos/acme/shop/issues' => [['message' => 'Resource not accessible by personal access token'], 403]];
        $this->post(route('connectors.github.connection.store', $this->project), $this->input);
        $this->gitHubAnswers = [];

        $this->post(route('connectors.github.connection.store', $this->project), [...$this->input, 'token' => 'renewed'])
            ->assertSessionHas('status', __('connectors.github.connected', ['name' => 'acme/shop']));

        expect(GitHubConfig::grantedBy(DataSourceConnection::query()->sole()->config))->toBe(GitHubPermission::cases());
    });

    it('says on the token which permission it lacks, saving nothing', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/actions/runs' => [['message' => 'Resource not accessible by personal access token'], 403]];

        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), $this->input)
            ->assertSessionHasErrors(['token' => __('connectors.github.errors.permission', ['permission' => 'Actions'])]);

        expect(DataSourceConnection::query()->count())->toBe(0);
    });

    it('refuses a repository that is not owner/name', function () {
        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), [...$this->input, 'repository' => 'acme'])
            ->assertSessionHasErrors('repository');
    });

    it('is for those who administer the project', function () {
        $this->actingAs($this->member)->post(route('connectors.github.connection.store', $this->project), $this->input)->assertForbidden();
    });
});

describe('the GitHub page', function () {
    it('offers to connect one, and keeps it out of the sidebar', function () {
        $this->actingAs($this->member)->get(route('connectors.github.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->component('connectors/github/index')->where('connection', null)->where('sidebar_project.has_github', false));
    });

    it('shows the key figures against the period before, and the pull requests, runs and releases', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->where('filters.period', '7d')
                ->where('summary.open_pull_requests', 4)
                ->where('summary.waiting_for_review', 1)
                ->where('summary.open_issues', 3)
                ->where('summary.merged', ['current' => 3, 'previous' => 3])
                ->where('summary.failed_runs', ['current' => 2, 'previous' => 2])
                ->where('summary.ci.result_label', 'Failed')
                ->where('summary.latest_release.tag', 'v1.2.0')
                ->has('pull_requests', 2)
                ->has('runs', 1)
                ->has('releases', 1)
                ->where('sidebar_project.has_github', true));
    });

    it('shows commits, lead time, stars, issues and security alerts', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', GitHubPermission::cases())]);

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->where('summary.lead_time_hours', ['current' => 4, 'previous' => 4])
                ->where('summary.stars', 42)
                ->where('commits.count', ['current' => 3, 'previous' => 17])
                ->where('commits.authors', 2)
                ->where('commits.contributors', [['author' => 'ana', 'commits' => 2], ['author' => 'Bo', 'commits' => 1]])
                ->has('commits.series', 28)
                ->where('commits.series.27', ['t' => '2026-11-10T06:00:00Z', 'commits' => 1])
                ->has('commits.latest', 3)
                ->where('issues', ['opened' => ['current' => 6, 'previous' => 6], 'closed' => ['current' => 2, 'previous' => 2], 'open_bugs' => 1])
                ->where('security.0.count', 3)
                ->where('security.0.by_severity', ['critical' => 1, 'high' => 2, 'medium' => 0, 'low' => 0])
                ->where('security.1.error', __('connectors.github.errors.unavailable', ['permission' => 'Code scanning alerts']))
                ->where('security.2.count', 0)
                ->where('permissions.0.granted', true));
    });

    it('asks nothing a token without the optional permissions cannot read', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->where('issues', null)
                ->where('security.0.granted', false)
                ->where('security.0.count', null)
                ->where('permissions.0', ['key' => 'issues', 'label' => 'Issues', 'unlocks' => __('connectors.github.permissions.unlocks.issues'), 'granted' => false]));

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'alerts') || str_contains(urldecode($request->url()), 'is:issue'));
    });

    it('says why when GitHub cannot be read', function () {
        DataSourceConnection::factory()->github()->for($this->project)->create();
        $this->gitHubAnswers = ['/repos/acme/shop' => [['message' => 'Bad credentials'], 401]];

        $this->actingAs($this->member)->get(route('connectors.github.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('error', __('connectors.github.errors.unauthorized'))->where('summary', null));
    });

    it('knows no repository on another kind of connection', function () {
        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, DataSourceConnection::factory()->mosparo()->for($this->project)->create()]))->assertNotFound();
    });
});
