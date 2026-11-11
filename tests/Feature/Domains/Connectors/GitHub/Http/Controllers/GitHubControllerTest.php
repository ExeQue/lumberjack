<?php

declare(strict_types=1);

use App\Domains\Connectors\GitHub\Actions\ConnectGitHubAction;
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
