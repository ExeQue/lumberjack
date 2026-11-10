<?php

declare(strict_types=1);

use App\Domains\Connectors\Mosparo\Actions\ConnectMosparoAction;
use App\Domains\Connectors\Mosparo\Http\Controllers\MosparoConnectionController;
use App\Domains\Connectors\Mosparo\Http\Controllers\MosparoController;
use App\Domains\Connectors\Mosparo\Http\Requests\ConnectMosparoRequest;
use App\Domains\Connectors\Mosparo\Queries\MosparoDashboardQuery;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Health\FakesHealthProbes;

covers(MosparoController::class, MosparoConnectionController::class, ConnectMosparoRequest::class, ConnectMosparoAction::class, MosparoDashboardQuery::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.data_sources_allow_private' => false]);
    $this->fakeDns();
    $this->travelTo('2026-11-10 12:00:00');
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->refused = false;
    // Two valid submissions today, one spam three days ago, one valid nine days ago.
    Http::fake(['mosparo.example.com/*' => fn () => $this->refused
        ? Http::response(['error' => true, 'errorMessage' => 'Request invalid.'], 400)
        : Http::response(['result' => true, 'data' => [
            'numberOfValidSubmissions' => 3, 'numberOfSpamSubmissions' => 1, 'numberOfDelayedRequests' => 0, 'numberOfBlockedRequests' => 1,
            'lastSubmissionAt' => '2026-11-10T09:00:00+00:00',
            'numbersByDate' => [
                '2026-11-10' => ['numberOfValidSubmissions' => 2, 'numberOfSpamSubmissions' => 0],
                '2026-11-07' => ['numberOfValidSubmissions' => 0, 'numberOfSpamSubmissions' => 1, 'numberOfBlockedRequests' => 1],
                '2026-11-01' => ['numberOfValidSubmissions' => 1, 'numberOfSpamSubmissions' => 0],
            ],
        ]])]);
    $this->keys = ['name' => 'Contact form', 'url' => 'https://mosparo.example.com/', 'public_key' => 'pub', 'private_key' => 'priv', 'timezone' => 'UTC'];
});

describe('connecting a mosparo project', function () {
    it('keeps the keys encrypted and opens its page', function () {
        $response = $this->actingAs($this->admin)->post(route('connectors.mosparo.connection.store', $this->project), $this->keys)->assertSessionHasNoErrors();

        $connection = DataSourceConnection::query()->sole();

        $response->assertRedirect(route('connectors.mosparo.index', [$this->project, $connection]));
        expect($connection->kind)->toBe(ConnectionKind::Mosparo)
            ->and($connection->url)->toBe('https://mosparo.example.com')
            ->and($connection->name)->toBe('Contact form')
            ->and($connection->mosparoCredentials()->privateKey)->toBe('priv')
            ->and(DB::table('data_source_connections')->value('config'))->not->toContain('priv');
    });

    it('renews a connection given the same public key again, and keeps others apart', function () {
        $this->actingAs($this->admin);
        $this->post(route('connectors.mosparo.connection.store', $this->project), $this->keys);
        $this->post(route('connectors.mosparo.connection.store', $this->project), [...$this->keys, 'private_key' => 'renewed']);
        $this->post(route('connectors.mosparo.connection.store', $this->project), [...$this->keys, 'name' => 'Newsletter', 'public_key' => 'other']);

        expect($this->project->mosparoConnections()->pluck('name')->all())->toBe(['Contact form', 'Newsletter'])
            ->and($this->project->mosparoConnections()->first()->mosparoCredentials()->privateKey)->toBe('renewed');
    });

    it('says on the key when mosparo refuses it, saving nothing', function () {
        $this->refused = true;

        $this->actingAs($this->admin)->post(route('connectors.mosparo.connection.store', $this->project), $this->keys)
            ->assertSessionHasErrors(['private_key' => __('connectors.mosparo.errors.unauthorized')]);

        expect(DataSourceConnection::query()->count())->toBe(0);
    });

    it('is for those who administer the project', function () {
        $this->actingAs($this->member)->post(route('connectors.mosparo.connection.store', $this->project), $this->keys)->assertForbidden();
    });
});

describe('the mosparo page', function () {
    it('offers to connect one, and keeps it out of the sidebar', function () {
        $this->actingAs($this->member)->get(route('connectors.mosparo.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->component('connectors/mosparo/index')->where('connection', null)->where('sidebar_project.has_mosparo', false));
    });

    it('shows a week\'s submissions against the week before, and each day of it', function () {
        $connection = DataSourceConnection::factory()->mosparo()->for($this->project)->create(['timezone' => 'UTC']);

        $this->actingAs($this->member)->get(route('connectors.mosparo.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->where('filters.period', '7d')
                ->where('summary.current', ['valid' => 2, 'spam' => 1, 'delayed' => 0, 'blocked' => 1, 'spam_rate' => 33.3])
                ->where('summary.previous', ['valid' => 1, 'spam' => 0, 'delayed' => 0, 'blocked' => 0, 'spam_rate' => 0])
                ->has('series', 7)
                ->where('series.0.t', '2026-11-04T00:00:00+00:00')
                ->where('series.6', ['t' => '2026-11-10T00:00:00+00:00', 'valid' => 2, 'spam' => 0])
                ->where('sidebar_project.has_mosparo', true));
    });

    it('shows today, each day of a month, and nothing finer than a day', function (string $period, string $shown, int $days) {
        $connection = DataSourceConnection::factory()->mosparo()->for($this->project)->create(['timezone' => 'UTC']);

        $this->actingAs($this->member)->get(route('connectors.mosparo.index', [$this->project, $connection, 'period' => $period]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.period', $shown)->has('series', $days));
    })->with([['24h', '24h', 1], ['30d', '30d', 30], ['1h', '7d', 7]]);

    it('says why when mosparo cannot be read', function () {
        DataSourceConnection::factory()->mosparo()->for($this->project)->create();
        $this->refused = true;

        $this->actingAs($this->member)->get(route('connectors.mosparo.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('error', __('connectors.mosparo.errors.unauthorized'))->where('summary', null));
    });

    it('knows no mosparo project on another kind of connection', function () {
        $this->actingAs($this->member)->get(route('connectors.mosparo.index', [$this->project, DataSourceConnection::factory()->umami()->for($this->project)->create()]))->assertNotFound();
    });
});
