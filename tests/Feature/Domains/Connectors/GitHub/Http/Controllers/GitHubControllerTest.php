<?php

declare(strict_types=1);

use App\Domains\Connectors\GitHub\Actions\ConnectGitHubAction;
use App\Domains\Connectors\GitHub\Actions\UpdateGitHubConnectionAction;
use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Enums\GitHubFigure;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Http\Controllers\GitHubConnectionController;
use App\Domains\Connectors\GitHub\Http\Controllers\GitHubController;
use App\Domains\Connectors\GitHub\Http\Requests\ConnectGitHubRequest;
use App\Domains\Connectors\GitHub\Http\Requests\UpdateGitHubConnectionRequest;
use App\Domains\Connectors\GitHub\Queries\GitHubDashboardQuery;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Connectors\FakesGitHub;

covers(GitHubController::class, GitHubConnectionController::class, ConnectGitHubRequest::class, UpdateGitHubConnectionRequest::class, ConnectGitHubAction::class, UpdateGitHubConnectionAction::class, GitHubDashboardQuery::class, GitHubFigure::class, GitHubConfig::class);

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

    it('keeps the key figures chosen when connected again, and the cached data while the branch is the same', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'old', figures: ['forks' => true])]);
        $window = DataSourceWindow::factory()->forSource(DataSource::factory()->connected()->for($this->project)->create(['data_source_connection_id' => $connection->id]))->create();

        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), $this->input)->assertSessionHasNoErrors();

        expect($connection->refresh()->config->figures)->toBe(['forks' => true])
            ->and($window->exists())->toBeTrue()
            ->and(DataSourceWindow::query()->count())->toBe(1);
    });

    it('drops the data read from the old branch when connected again on another', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();
        DataSourceWindow::factory()->forSource(DataSource::factory()->connected()->for($this->project)->create(['data_source_connection_id' => $connection->id]))->create();

        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), [...$this->input, 'branch' => 'develop'])->assertSessionHasNoErrors();

        expect(GitHubConfig::branchOf($connection->refresh()->config))->toBe('develop')
            ->and(DataSourceWindow::query()->count())->toBe(0);
    });

    it('renews a connection whose config was saved under another APP_KEY', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();
        DB::table('data_source_connections')->where('id', $connection->id)->update(['config' => 'not-decryptable']);

        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), $this->input)->assertSessionHasNoErrors();

        expect($connection->refresh()->gitHubCredentials()->token)->toBe('github_pat_test');
    });

    it('keeps one connection per repository in the database, as two connects at once would race', function () {
        DataSourceConnection::factory()->github()->for($this->project)->create();

        expect(fn () => DataSourceConnection::factory()->github()->for($this->project)->create())->toThrow(UniqueConstraintViolationException::class);
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
            ->assertInertia(fn (Assert $page) => $page->component('connectors/github/index')->where('connection', null)->where('sidebar_project.connectors', []));
    });

    it('shows the key figures against the period before, and the pull requests, runs and releases', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', figures: collect(GitHubFigure::cases())->mapWithKeys(fn (GitHubFigure $figure): array => [$figure->value => true])->all())]);

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
                ->has('sidebar_project.connectors.github', 1));
    });

    it('shows commits, lead time, stars, issues and security alerts', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', GitHubPermission::cases(), figures: collect(GitHubFigure::cases())->mapWithKeys(fn (GitHubFigure $figure): array => [$figure->value => true])->all())]);

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

    it('says the token cannot be read when the config was saved under another APP_KEY', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();
        DB::table('data_source_connections')->where('id', $connection->id)->update(['config' => 'not-decryptable']);

        $this->actingAs($this->admin)->get(route('connectors.github.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('error', __('connectors.github.errors.secret_unreadable'))->where('connection.branch', null));

        $this->put(route('connectors.github.connection.update', [$this->project, $connection]), ['branch' => 'develop'])
            ->assertSessionHasErrors(['branch' => __('connectors.github.errors.secret_unreadable')]);
    });

    it('sends each release\'s notes as written', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page->where('releases.0.body', "## Fixed\n\n- The cart <script>keeps</script> its items."));
    });

    it('links each figure to its page on GitHub, searched for the period and the branch read', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', GitHubPermission::cases(), 'develop', collect(GitHubFigure::cases())->mapWithKeys(fn (GitHubFigure $figure): array => [$figure->value => true])->all())]);

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('links.open_pull_requests', 'https://github.com/acme/shop/pulls')
                ->where('links.merged', 'https://github.com/acme/shop/pulls?q=is%3Apr%20is%3Amerged%20merged%3A2026-11-03T12%3A00%3A00Z..2026-11-10T12%3A00%3A00Z')
                ->where('links.commits', 'https://github.com/acme/shop/commits/develop')
                ->where('links.failed_runs', 'https://github.com/acme/shop/actions?query=is%3Afailure%20branch%3Adevelop')
                ->where('links.ci', 'https://github.com/acme/shop/actions/runs/1')
                ->where('links.latest_release', 'https://github.com/acme/shop/releases/tag/v1.2.0')
                ->where('links.issues_closed', 'https://github.com/acme/shop/issues?q=is%3Aissue%20is%3Aclosed%20closed%3A2026-11-03T12%3A00%3A00Z..2026-11-10T12%3A00%3A00Z')
                ->where('links.dependabot_alerts', 'https://github.com/acme/shop/security/dependabot'));
    });

    it('searches failed runs on every branch when none is chosen', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', figures: ['failed_runs' => true])]);

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('links.failed_runs', 'https://github.com/acme/shop/actions?query=is%3Afailure')
                ->where('links.commits', 'https://github.com/acme/shop/commits'));
    });

    it('knows no repository on another kind of connection', function () {
        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, DataSourceConnection::factory()->mosparo()->for($this->project)->create()]))->assertNotFound();
    });
});

describe('loading more of a list', function () {
    beforeEach(function () {
        $this->connection = DataSourceConnection::factory()->github()->for($this->project)->create();
    });

    it('asks GitHub for a bigger page of pull requests, runs and releases', function () {
        $pulls = array_map(fn (int $n): array => ['number' => $n, 'title' => 'Pull '.$n, 'user' => ['login' => 'ana'], 'draft' => false, 'html_url' => 'https://github.com/acme/shop/pull/'.$n, 'created_at' => '2026-11-10T09:00:00Z'], range(1, 40));
        $this->gitHubAnswers = ['/repos/acme/shop/pulls' => [$pulls]];

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $this->connection, 'rows' => ['pull_requests' => 40, 'runs' => 60, 'releases' => 80]]))
            ->assertInertia(fn (Assert $page) => $page->where('rows', ['pull_requests' => 40, 'runs' => 60, 'releases' => 80])->has('pull_requests', 40));

        Http::assertSent(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/pulls') && $request['per_page'] === 40);
        Http::assertSent(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/actions/runs') && $request['per_page'] === 60);
        Http::assertSent(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/releases') && $request['per_page'] === 80);
    });

    it('shows more of the period\'s commits and contributors without asking GitHub again for them', function () {
        $commits = array_map(fn (int $n): array => self::commit('s'.$n, 'author'.$n, 'Author '.$n, '2026-11-09T'.sprintf('%02d', $n % 24).':00:00Z'), range(1, 25));
        $this->gitHubAnswers = ['/repos/acme/shop/commits' => [$commits]];

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $this->connection]))
            ->assertInertia(fn (Assert $page) => $page->has('commits.latest', 20)->has('commits.contributors', 10));

        Http::fake();
        $this->get(route('connectors.github.index', [$this->project, $this->connection, 'rows' => ['commits' => 40, 'contributors' => 20]]))
            ->assertInertia(fn (Assert $page) => $page->has('commits.latest', 25)->has('commits.contributors', 20));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/commits'));
    });

    it('holds each list to its most', function () {
        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $this->connection, 'rows' => ['pull_requests' => 9999, 'commits' => 99999, 'contributors' => 500]]))
            ->assertInertia(fn (Assert $page) => $page->where('rows', ['pull_requests' => 100, 'commits' => 1000, 'contributors' => 100]));

        Http::assertSent(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/pulls') && $request['per_page'] === 100);
    });

    it('shows the first rows for a count it cannot read', function (mixed $rows) {
        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $this->connection, 'rows' => ['runs' => $rows, 'unknown' => 50]]))
            ->assertInertia(fn (Assert $page) => $page->where('rows', []));

        Http::assertSent(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/actions/runs') && $request['per_page'] === 20);
    })->with([
        'a word' => ['many'],
        'fewer than at first' => [5],
        'a negative number' => [-40],
    ]);
});

describe('the branch read', function () {
    /** Whether a request went to the path ending so, with the query value. */
    $sentTo = fn (string $path, string $key, string $value): Closure => fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path) && ($request->data()[$key] ?? null) === $value;

    it('is the default branch when none is given', function () use ($sentTo) {
        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), $this->input)->assertSessionHasNoErrors();
        $connection = DataSourceConnection::query()->sole();

        expect(GitHubConfig::branchOf($connection->config))->toBeNull();

        $this->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page->where('connection.branch', null)->where('summary.ci.branch', 'main'));

        Http::assertSent($sentTo('/commits', 'sha', 'main'));
        Http::assertSent($sentTo('/actions/runs', 'branch', 'main'));
        // Failed runs count on every branch, as before a branch could be chosen.
        Http::assertNotSent(fn (Request $request): bool => ($request->data()['status'] ?? null) === 'failure' && isset($request->data()['branch']));
    });

    it('reads commits, CI and failed runs from the branch chosen when connecting', function () use ($sentTo) {
        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), [...$this->input, 'branch' => 'develop'])->assertSessionHasNoErrors();
        $connection = DataSourceConnection::query()->sole();

        expect(GitHubConfig::branchOf($connection->config))->toBe('develop');

        $this->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page->where('connection.branch', 'develop'));

        Http::assertSent($sentTo('/commits', 'sha', 'develop'));
        Http::assertSent(fn (Request $request): bool => ($request->data()['branch'] ?? null) === 'develop' && ($request->data()['exclude_pull_requests'] ?? null) === 'true');
        Http::assertSent(fn (Request $request): bool => ($request->data()['branch'] ?? null) === 'develop' && ($request->data()['status'] ?? null) === 'failure');
        Http::assertNotSent($sentTo('/commits', 'sha', 'main'));
    });

    it('refuses a branch the repository does not have, saving nothing', function () {
        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), [...$this->input, 'branch' => 'nope'])
            ->assertSessionHasErrors(['branch' => __('connectors.github.errors.branch_not_found', ['branch' => 'nope', 'repository' => 'acme/shop'])]);

        expect(DataSourceConnection::query()->count())->toBe(0);
    });

    it('refuses what cannot be a branch name', function (string $branch) {
        $this->actingAs($this->admin)->post(route('connectors.github.connection.store', $this->project), [...$this->input, 'branch' => $branch])
            ->assertSessionHasErrors('branch');
    })->with([
        'a space' => ['my branch'],
        'two dots' => ['a..b'],
        'a leading dash' => ['-x'],
        'a trailing slash' => ['feature/'],
    ]);

    it('changes on a connection, and back to the default branch', function () use ($sentTo) {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();
        $this->actingAs($this->admin);
        $this->get(route('connectors.github.index', [$this->project, $connection]));

        $this->put(route('connectors.github.connection.update', [$this->project, $connection]), ['branch' => 'develop'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('connectors.github.branch_changed', ['branch' => 'develop']));

        expect(GitHubConfig::branchOf($connection->refresh()->config))->toBe('develop')
            ->and($connection->gitHubCredentials()->token)->toBe('github_pat_test')
            ->and($connection->next_check_at)->toBeNull();

        // Its figures are not the cached ones of the default branch.
        $this->get(route('connectors.github.index', [$this->project, $connection]))->assertInertia(fn (Assert $page) => $page->where('connection.branch', 'develop'));
        Http::assertSent($sentTo('/commits', 'sha', 'develop'));

        $this->put(route('connectors.github.connection.update', [$this->project, $connection]), ['branch' => ''])
            ->assertSessionHas('status', __('connectors.github.branch_default'));

        expect(GitHubConfig::branchOf($connection->refresh()->config))->toBeNull();
    });

    it('keeps the branch when the one asked for is unknown', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->admin)->put(route('connectors.github.connection.update', [$this->project, $connection]), ['branch' => 'nope'])
            ->assertSessionHasErrors(['branch' => __('connectors.github.errors.branch_not_found', ['branch' => 'nope', 'repository' => 'acme/shop'])]);

        expect(GitHubConfig::branchOf($connection->refresh()->config))->toBeNull();
    });

    it('is changed only by those who administer the project', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->member)->put(route('connectors.github.connection.update', [$this->project, $connection]), ['branch' => 'develop'])->assertForbidden();

        expect(GitHubConfig::branchOf($connection->refresh()->config))->toBeNull();
    });

    it('is changed only on a GitHub connection of the project', function () {
        $other = DataSourceConnection::factory()->github()->create();

        $this->actingAs($this->admin)->put(route('connectors.github.connection.update', [$this->project, DataSourceConnection::factory()->mosparo()->for($this->project)->create()]), ['branch' => 'develop'])->assertNotFound();
        $this->put(route('connectors.github.connection.update', [$this->project, $other]), ['branch' => 'develop'])->assertNotFound();
    });
});

describe('the key figures shown', function () {
    it('shows by default all but lead time, authors, failed runs and the latest release, stars for a public repository', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', GitHubPermission::cases())]);

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('figures', fn ($figures): bool => $figures->all() === ['open_pull_requests', 'waiting_for_review', 'merged', 'commits', 'ci', 'open_issues', 'issues_opened', 'issues_closed', 'open_bugs', 'stars', 'dependabot_alerts', 'code_scanning_alerts', 'secret_scanning_alerts'])
                ->where('summary.stars', 42)
                ->missing('summary.lead_time_hours')
                ->missing('summary.failed_runs')
                ->missing('summary.latest_release')
                ->missing('links.failed_runs')
                ->where('links.stars', 'https://github.com/acme/shop/stargazers'));
    });

    it('shows forks and watchers as cards of their own once switched on, off by default', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->admin)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('figures', fn ($figures): bool => $figures->contains('stars') && $figures->intersect(['forks', 'watchers'])->isEmpty())
                ->missing('summary.forks')
                ->missing('summary.watchers')
                ->where('connection.figure_choices', fn ($choices): bool => collect($choices)->where('group', 'community')->pluck('key')->all() === ['stars', 'forks', 'watchers']));

        $this->put(route('connectors.github.connection.update', [$this->project, $connection]), ['figures' => ['stars', 'forks', 'watchers']])->assertSessionHasNoErrors();
        expect($connection->refresh()->config->figures)->toMatchArray(['forks' => true, 'watchers' => true])->not->toHaveKey('stars');

        $this->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('figures', ['stars', 'forks', 'watchers'])
                ->where('summary.stars', 42)
                ->where('summary.forks', 5)
                ->where('summary.watchers', 3)
                ->where('links.forks', 'https://github.com/acme/shop/forks')
                ->where('links.watchers', 'https://github.com/acme/shop/watchers'));
    });

    it('hides the stars of a private repository by default', function () {
        $this->gitHubAnswers = ['/repos/acme/shop' => [['full_name' => 'acme/shop', 'default_branch' => 'main', 'private' => true, 'stargazers_count' => 42]]];
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('figures', fn ($figures): bool => ! in_array('stars', $figures->all(), true))
                ->missing('summary.stars')
                ->missing('summary.forks')
                ->missing('links.stars'));
    });

    it('never shows a figure whose permission the token lacks, and asks nothing for the issues it hides', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', [GitHubPermission::Issues], figures: ['dependabot_alerts' => true, 'issues_opened' => false, 'issues_closed' => false, 'open_bugs' => false, 'open_pull_requests' => false])]);

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('figures', fn ($figures): bool => $figures->intersect(['dependabot_alerts', 'issues_opened', 'issues_closed', 'open_bugs', 'open_pull_requests'])->isEmpty() && $figures->contains('open_issues'))
                ->where('issues', null)
                ->missing('summary.open_pull_requests')
                ->where('summary.open_issues', 3));

        Http::assertNotSent(fn ($request): bool => str_contains(urldecode($request->url()), 'is:issue'));
    });

    it('remembers only the figures switched otherwise than by default, keeping the branch and the data sources', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', branch: 'develop'), 'next_check_at' => now()->addHour()]);
        app(SyncDataSourceConnectionAction::class)->execute($connection->id);
        $sources = DataSource::query()->where('data_source_connection_id', $connection->id)->pluck('key')->sort()->values()->all();

        $this->actingAs($this->admin)->put(route('connectors.github.connection.update', [$this->project, $connection]), ['branch' => 'develop', 'figures' => ['failed_runs', 'open_pull_requests', 'merged', 'commits', 'ci', 'open_issues']])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('connectors.github.settings_saved'));

        $connection->refresh();
        expect($connection->config->figures)->toEqual(['waiting_for_review' => false, 'failed_runs' => true, 'stars' => false])
            ->and(GitHubConfig::branchOf($connection->config))->toBe('develop')
            ->and($connection->next_check_at)->not->toBeNull();

        app(SyncDataSourceConnectionAction::class)->execute($connection->id);
        expect(DataSource::query()->where('data_source_connection_id', $connection->id)->pluck('key')->sort()->values()->all())->toBe($sources)
            ->and($sources)->toContain('stars', 'failed_runs', 'lead_time');

        $this->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('figures', ['open_pull_requests', 'merged', 'commits', 'ci', 'failed_runs', 'open_issues'])
                ->where('summary.failed_runs', ['current' => 2, 'previous' => 2])
                ->missing('summary.stars')
                ->missing('summary.waiting_for_review'));
    });

    it('shows the figures of a permission granted after a save, as by default', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();
        $this->actingAs($this->admin)->put(route('connectors.github.connection.update', [$this->project, $connection]), ['figures' => ['open_pull_requests']])->assertSessionHasNoErrors();

        $config = $connection->refresh()->config;
        $connection->update(['config' => new GitHubConfig('acme/shop', 'github_pat_test', [GitHubPermission::Issues], figures: $config->figures)]);

        $this->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page->where('figures', ['open_pull_requests', 'issues_opened', 'issues_closed', 'open_bugs']));
    });

    it('shows the stars of a repository made public after a save, unless they were switched off', function () {
        $private = ['/repos/acme/shop' => [['full_name' => 'acme/shop', 'default_branch' => 'main', 'private' => true, 'stargazers_count' => 42]]];
        $this->gitHubAnswers = $private;
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();
        $this->actingAs($this->admin)->put(route('connectors.github.connection.update', [$this->project, $connection]), ['figures' => ['open_pull_requests']])->assertSessionHasNoErrors();

        expect($connection->refresh()->config->figures)->not->toHaveKey('stars');

        $this->gitHubAnswers = [];
        Cache::flush();
        $this->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page->where('figures', ['open_pull_requests', 'stars'])->where('summary.stars', 42));

        // Switched off while public, they stay off.
        $this->put(route('connectors.github.connection.update', [$this->project, $connection]), ['figures' => ['open_pull_requests']])->assertSessionHasNoErrors();
        expect($connection->refresh()->config->figures)->toMatchArray(['stars' => false]);

        Cache::flush();
        $this->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page->where('figures', ['open_pull_requests'])->missing('summary.stars'));
    });

    it('saves every figure switched off, and keeps the figures when none are sent', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();
        $this->actingAs($this->admin);

        $this->put(route('connectors.github.connection.update', [$this->project, $connection]), ['figures' => []])->assertSessionHasNoErrors();
        $off = ['open_pull_requests' => false, 'waiting_for_review' => false, 'merged' => false, 'commits' => false, 'ci' => false, 'open_issues' => false, 'stars' => false];
        expect($connection->refresh()->config->figures)->toEqual($off);

        $this->put(route('connectors.github.connection.update', [$this->project, $connection]), ['branch' => ''])->assertSessionHasNoErrors();
        expect($connection->refresh()->config->figures)->toEqual($off);

        $this->get(route('connectors.github.index', [$this->project, $connection]))->assertInertia(fn (Assert $page) => $page->where('figures', []));
    });

    it('refuses a figure it does not know', function (mixed $figures) {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->admin)->put(route('connectors.github.connection.update', [$this->project, $connection]), ['figures' => $figures])
            ->assertSessionHasErrors();

        expect($connection->refresh()->config->figures)->toBe([]);
    })->with([
        'an unknown key' => [['stars', 'nope']],
        'not a list' => ['stars'],
    ]);

    it('is changed only by those who administer the project', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        $this->actingAs($this->member)->put(route('connectors.github.connection.update', [$this->project, $connection]), ['figures' => ['stars']])->assertForbidden();

        expect($connection->refresh()->config->figures)->toBe([]);
    });

    it('offers the figures the token can show to those who administer the project only', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', [GitHubPermission::SecretScanningAlerts])]);

        $this->actingAs($this->admin)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.figure_choices', fn ($choices): bool => collect($choices)->pluck('key')->doesntContain('issues_opened') && collect($choices)->contains(['key' => 'secret_scanning_alerts', 'group' => 'security'])));

        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page->missing('connection.figure_choices'));
    });
});

describe('the branch field\'s suggestions', function () {
    beforeEach(function () {
        $this->connection = DataSourceConnection::factory()->github()->for($this->project)->create();
    });

    it('loads the default branch and the branches after the page, the default first', function () {
        $this->gitHubAnswers['/repos/acme/shop/branches'] = [[['name' => 'zeta'], ['name' => 'main'], ['name' => 'Alpha'], ['name' => 'develop']]];

        $this->actingAs($this->admin)->get(route('connectors.github.index', [$this->project, $this->connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('connection.default_branch')
                ->missing('connection.branches')
                ->loadDeferredProps('branches', fn (Assert $reload) => $reload
                    ->where('connection.default_branch', 'main')
                    ->where('connection.branches', ['main', 'Alpha', 'develop', 'zeta'])));
    });

    it('is not sent to members, who cannot change the branch', function () {
        $this->actingAs($this->member)->get(route('connectors.github.index', [$this->project, $this->connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('connection.default_branch')
                ->missing('connection.branches')
                ->reload(only: ['connection.default_branch', 'connection.branches'], callback: fn (Assert $reload) => $reload
                    ->missing('connection.default_branch')
                    ->missing('connection.branches')));
    });

    it('reads at most three pages of branches', function () {
        $this->gitHubAnswers['/repos/acme/shop/branches'] = [[['name' => 'main']], 200, ['Link' => '<https://api.github.com/repos/acme/shop/branches?per_page=100&page=2>; rel="next"']];

        $this->actingAs($this->admin)->get(route('connectors.github.index', [$this->project, $this->connection]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('branches', fn (Assert $reload) => $reload->where('connection.default_branch', 'main')));

        expect(Http::recorded(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/branches')))->toHaveCount(GitHubClient::BRANCH_PAGES);
    });

    it('suggests nothing when GitHub cannot be read', function () {
        $this->gitHubAnswers['/repos/acme/shop/branches'] = [['message' => 'Server error'], 500];

        $this->actingAs($this->admin)->get(route('connectors.github.index', [$this->project, $this->connection]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('branches', fn (Assert $reload) => $reload
                ->where('connection.default_branch', null)
                ->where('connection.branches', [])));
    });
});

describe('asking GitHub for the summary', function () {
    it('asks for the independent figures at once, each call as before', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();

        app(GitHubDashboardQuery::class)->summary($connection, Period::Week);

        $sent = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->method().' '.urldecode((string) preg_replace('~^https://api\.github\.com~', '', $pair[0]->url())))->sort()->values()->all();

        expect($sent)->toBe(collect([
            'GET /repos/acme/shop',
            'GET /search/issues?q=repo:acme/shop is:pr is:open&per_page=1',
            'GET /search/issues?q=repo:acme/shop is:pr is:open draft:false review:none&per_page=1',
            'GET /search/issues?q=repo:acme/shop is:pr is:merged merged:2026-11-03T12:00:00Z..2026-11-10T12:00:00Z&per_page=100',
            'GET /search/issues?q=repo:acme/shop is:pr is:merged merged:2026-10-27T12:00:00Z..2026-11-03T12:00:00Z&per_page=100',
            'GET /repos/acme/shop/actions/runs?status=failure&created=2026-11-03T12:00:00Z..2026-11-10T12:00:00Z&per_page=1',
            'GET /repos/acme/shop/actions/runs?status=failure&created=2026-10-27T12:00:00Z..2026-11-03T12:00:00Z&per_page=1',
            'GET /repos/acme/shop/actions/runs?branch=main&exclude_pull_requests=true&per_page=1',
            'GET /repos/acme/shop/releases?per_page=5',
        ])->sort()->values()->all());
    });

    it('still names the one call GitHub refused', function () {
        $connection = DataSourceConnection::factory()->github()->for($this->project)->create();
        $this->gitHubAnswers = ['/repos/acme/shop/actions/runs' => [['message' => 'Resource not accessible by personal access token'], 403]];

        expect(fn () => app(GitHubDashboardQuery::class)->summary($connection, Period::Week))
            ->toThrow(GitHubRequestFailedException::class, __('connectors.github.errors.permission', ['permission' => 'Actions']));
    });
});
