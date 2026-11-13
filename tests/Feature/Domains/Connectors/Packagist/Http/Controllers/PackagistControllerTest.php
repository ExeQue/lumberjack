<?php

declare(strict_types=1);

use App\Domains\Connectors\Packagist\Actions\ConnectPackagistAction;
use App\Domains\Connectors\Packagist\Http\Controllers\PackagistConnectionController;
use App\Domains\Connectors\Packagist\Http\Controllers\PackagistController;
use App\Domains\Connectors\Packagist\Http\Requests\ConnectPackagistRequest;
use App\Domains\Connectors\Packagist\Http\Requests\SearchPackagistRequest;
use App\Domains\Connectors\Packagist\Queries\PackagistDashboardQuery;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Connectors\FakesPackagist;

covers(PackagistController::class, PackagistConnectionController::class, ConnectPackagistRequest::class, SearchPackagistRequest::class, ConnectPackagistAction::class, PackagistDashboardQuery::class);

uses(FakesPackagist::class);

beforeEach(function () {
    $this->travelTo('2026-11-13 12:00:00');
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->fakePackagist();
});

describe('connecting a package', function () {
    it('saves the package Packagist knows and opens its page', function () {
        $response = $this->actingAs($this->admin)->post(route('connectors.packagist.connection.store', $this->project), ['package' => ' Acme/Widgets '])->assertSessionHasNoErrors();
        $connection = DataSourceConnection::query()->sole();

        $response->assertRedirect(route('connectors.packagist.index', [$this->project, $connection]));
        expect($connection->kind)->toBe(ConnectionKind::Packagist)
            ->and($connection->name)->toBe('acme/widgets')
            ->and($connection->packagistConfig()->package)->toBe('acme/widgets');
    });

    it('renews the connection when the same package is connected again', function () {
        foreach ([1, 2] as $time) {
            $this->actingAs($this->admin)->post(route('connectors.packagist.connection.store', $this->project), ['package' => 'acme/widgets']);
        }

        expect(DataSourceConnection::query()->count())->toBe(1);
    });

    it('says so on the field when Packagist has no such package', function () {
        $this->actingAs($this->admin)->post(route('connectors.packagist.connection.store', $this->project), ['package' => 'acme/missing'])
            ->assertSessionHasErrors(['package' => __('connectors.packagist.errors.not_found')]);
        $this->actingAs($this->admin)->post(route('connectors.packagist.connection.store', $this->project), ['package' => 'not a package'])
            ->assertSessionHasErrors('package');

        expect(DataSourceConnection::query()->count())->toBe(0);
    });

    it('finds packages as they are typed, leaving virtual ones out', function () {
        $this->actingAs($this->admin)->getJson(route('connectors.packagist.search', [$this->project, 'q' => 'widg']))
            ->assertOk()
            ->assertExactJson([['name' => 'acme/widgets', 'description' => 'Widgets for everyone.', 'downloads' => 12345]]);
    });

    it('is for team owners and admins only', function () {
        $this->actingAs($this->member)->post(route('connectors.packagist.connection.store', $this->project), ['package' => 'acme/widgets'])->assertForbidden();
        $this->actingAs($this->member)->getJson(route('connectors.packagist.search', [$this->project, 'q' => 'widg']))->assertForbidden();
    });
});

describe('the page', function () {
    beforeEach(function () {
        $this->connection = DataSourceConnection::factory()->packagist()->for($this->project)->create();
    });

    it('shows the period\'s downloads against the one before, by version, with releases and advisories', function () {
        $this->actingAs($this->member)->get(route('connectors.packagist.index', [$this->project, 'period' => '7d']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connectors/packagist/index')
                ->where('connection.name', 'acme/widgets')
                ->where('error', null)
                ->where('summary.downloads', 90)
                ->where('summary.previous', 70)
                ->where('summary.through', '2026-11-12')
                ->where('summary.total', 12345)
                ->where('summary.advisories', 1)
                ->has('series', 7)
                ->where('majors.0.major', '2')
                ->where('majors.0.downloads', 70)
                ->where('releases', [['version' => 'v2.1.0', 'time' => '2026-11-01T10:00:00+00:00'], ['version' => 'v1.0.0', 'time' => '2025-06-01T10:00:00+00:00']])
                ->where('advisories.0.severity', 'critical')
                ->where('package.url', 'https://packagist.org/packages/acme/widgets'));
    });

    it('reads a day for the last 24 hours, as Packagist has nothing finer', function () {
        $this->actingAs($this->member)->get(route('connectors.packagist.index', [$this->project, 'period' => '1h']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.period', '30d'));
        $this->actingAs($this->member)->get(route('connectors.packagist.index', [$this->project, 'period' => '24h']))
            ->assertInertia(fn (Assert $page) => $page->where('summary.downloads', 20)->has('series', 1));
    });

    it('says why when Packagist cannot be read', function () {
        $this->packagistStatuses['/packages/acme/widgets.json'] = 429;

        $this->actingAs($this->member)->get(route('connectors.packagist.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('error', __('connectors.packagist.errors.rate_limited'))->where('summary', null));
    });

    it('opens no other kind of connection', function () {
        $this->actingAs($this->member)->get(route('connectors.packagist.index', [$this->project, DataSourceConnection::factory()->mosparo()->for($this->project)->create()]))->assertNotFound();
    });
});
