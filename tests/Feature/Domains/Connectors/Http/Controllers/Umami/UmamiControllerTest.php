<?php

declare(strict_types=1);

use App\Domains\Connectors\Enums\Connector;
use App\Domains\Connectors\Http\Controllers\Umami\UmamiController;
use App\Domains\Connectors\Models\Umami\UtmLabel;
use App\Domains\Connectors\Queries\Umami\UmamiDashboardQuery;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Health\FakesHealthProbes;

covers(UmamiController::class, UmamiDashboardQuery::class, Connector::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.data_sources_allow_private' => false]);
    $this->fakeDns();
    $this->travelTo('2026-11-10 12:00:00');
    $this->project = Project::factory()->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
});

describe('a project without Umami', function () {
    it('offers to connect one, and keeps it out of the sidebar', function () {
        $this->actingAs($this->member)->get(route('connectors.umami.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->component('connectors/umami/index')
                ->where('connection', null)
                ->where('sidebar_project.has_umami', false));
    });

    it('lists Umami in the catalog of the data sources page as not connected', function () {
        $this->actingAs($this->member)->get(route('data-sources.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('connectors.0.key', 'umami')->where('connectors.0.connections', 0));
    });
});

describe('a project reading an Umami website', function () {
    beforeEach(function () {
        DataSourceConnection::factory()->umami()->for($this->project)->create(['timezone' => 'UTC']);
        $this->annotations = [];
        Http::fake(['umami.example.com/api/websites/site-1/annotations*' => fn () => Http::response(['data' => $this->annotations])]);
    });

    it('names its sources on the data sources page by their website', function () {
        $connection = $this->project->umamiConnections()->sole();
        DataSource::factory()->for($this->project)->connected('visitors')->create(['data_source_connection_id' => $connection->id, 'environment' => $connection->environment]);

        $this->actingAs($this->member)->get(route('data-sources.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('connected.0.environment', 'Shop')->where('connectors.0.connections', 1));
    });

    it('shows the website asked for, the first by name otherwise, and lists them all', function () {
        $blog = DataSourceConnection::factory()->umami('site-2')->for($this->project)->create(['website_name' => 'Blog']);
        Http::fake(['umami.example.com/api/websites/*' => Http::response([])]);
        $page = fn (?string $connection) => $this->actingAs($this->member)->get(route('connectors.umami.index', [$this->project, 'connection' => $connection]));

        $page(null)->assertInertia(fn (Assert $page) => $page->where('connection.uuid', $blog->uuid)->where('websites.0.name', 'Blog')->where('websites.1.name', 'Shop'));
        $page('gone')->assertInertia(fn (Assert $page) => $page->where('connection.uuid', $blog->uuid));
        $page($this->project->umamiConnections()->where('website_id', 'site-1')->value('uuid'))->assertInertia(fn (Assert $page) => $page->where('connection.website_id', 'site-1'));
    });

    it('marks the period\'s notes, a whole day at its start in the connection\'s zone', function () {
        DataSourceConnection::query()->update(['timezone' => 'Europe/Copenhagen']);
        Http::fake(['umami.example.com/api/websites/site-1/*' => Http::response([])]);
        $this->annotations = [
            ['id' => 'n1', 'date' => '2026-11-10T00:00:00Z', 'allDay' => true, 'note' => 'Launch'],
            ['id' => 'n2', 'date' => '2026-11-10T08:30:00Z', 'allDay' => false, 'note' => 'Mail sent'],
            ['id' => 'n3', 'date' => '2026-10-01T00:00:00Z', 'allDay' => true, 'note' => 'Too old'],
        ];

        $this->actingAs($this->member)->get(route('connectors.umami.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('annotations', [
                ['id' => 'n1', 'at' => '2026-11-10T00:00:00+01:00', 'all_day' => true, 'note' => 'Launch'],
                ['id' => 'n2', 'at' => '2026-11-10T08:30:00+00:00', 'all_day' => false, 'note' => 'Mail sent'],
            ]));
    });

    it('shows the totals and traffic, filtered by the page\'s filters', function () {
        Http::fake([
            'umami.example.com/api/websites/site-1/stats*' => Http::response(['visitors' => 40, 'visits' => 50, 'pageviews' => 120, 'bounces' => 10, 'totaltime' => 5000, 'comparison' => ['visitors' => 20, 'visits' => 25, 'pageviews' => 60, 'bounces' => 5, 'totaltime' => 2500]]),
            'umami.example.com/api/websites/site-1/active' => Http::response(['visitors' => 3]),
            'umami.example.com/api/websites/site-1/pageviews*' => Http::response(['pageviews' => [], 'sessions' => [['x' => '2026-11-10 11:00:00', 'y' => 4]]]),
        ]);

        $this->actingAs($this->member)->get(route('connectors.umami.index', [$this->project, 'filter' => ['country' => 'DK', 'password' => 'x']]))
            ->assertInertia(fn (Assert $page) => $page->component('connectors/umami/index')
                ->where('error', null)
                ->where('summary.current.visitors', 40)
                ->where('summary.current.bounce_rate', 20)
                ->where('summary.previous.visitors', 20)
                ->where('active', 3)
                ->where('filters.filter', ['country' => 'DK'])
                ->where('sidebar_project.has_umami', true)
                ->has('series', 24));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/stats') && str_contains($request->url(), 'country=DK') && ! str_contains($request->url(), 'password'));
    });

    it('loads each card\'s breakdowns after the page, pages with their Web Vitals', function () {
        Http::fake([
            'umami.example.com/api/websites/site-1/stats*' => Http::response(['visitors' => 1, 'visits' => 1, 'pageviews' => 1, 'bounces' => 0, 'totaltime' => 0]),
            'umami.example.com/api/websites/site-1/active' => Http::response(['visitors' => 0]),
            'umami.example.com/api/websites/site-1/pageviews*' => Http::response(['pageviews' => [], 'sessions' => []]),
            'umami.example.com/api/websites/site-1/metrics/expanded*' => Http::response([['name' => '/orders/42', 'pageviews' => 9, 'visitors' => 5, 'visits' => 6, 'bounces' => 3, 'totaltime' => 60]]),
        ]);
        DB::table('web_vitals')->insert(['project_id' => $this->project->id, 'environment' => 'production', 'route' => '/orders/{id}', 'lcp_us' => 1_800_000, 'device' => 'desktop', 'occurred_at' => now()->subHour()]);

        $this->actingAs($this->member)->get(route('connectors.umami.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('pages')
                ->loadDeferredProps('pages', fn (Assert $reload) => $reload
                    ->where('pages.path.0.name', '/orders/42')
                    ->where('pages.path.0.bounce_rate', 50)
                    ->where('pages.path.0.lcp_us', 1_800_000)));
    });

    it('lays visitors out by hour of the week in the connection\'s zone, Monday first', function () {
        $this->project->umamiConnections()->sole()->update(['timezone' => 'Europe/Copenhagen']);
        Http::fake([
            'umami.example.com/api/websites/site-1/stats*' => Http::response(['visitors' => 1, 'visits' => 1, 'pageviews' => 1, 'bounces' => 0, 'totaltime' => 0]),
            'umami.example.com/api/websites/site-1/active' => Http::response(['visitors' => 0]),
            'umami.example.com/api/websites/site-1/pageviews*' => Http::response(['pageviews' => [], 'sessions' => [
                // Monday 9 November, 23:00 in Copenhagen, and twice Tuesday 07:00.
                ['x' => '2026-11-09T23:00:00Z', 'y' => 2],
                ['x' => '2026-11-10T07:00:00Z', 'y' => 3],
                ['x' => '2026-11-03T07:00:00Z', 'y' => 1],
            ]]),
        ]);

        $this->actingAs($this->member)->get(route('connectors.umami.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('heatmap', fn (Assert $reload) => $reload
                ->has('heatmap', 7)
                ->where('heatmap.0.23', 2)
                ->where('heatmap.1.7', 4)));
    });

    it('names the UTM values of a breakdown as they were named', function () {
        UtmLabel::factory()->for($this->project->umamiConnections()->sole(), 'connection')->create(['value' => '5251806', 'label' => 'Black Friday']);
        Http::fake([
            'umami.example.com/api/websites/site-1/stats*' => Http::response(['visitors' => 1, 'visits' => 1, 'pageviews' => 1, 'bounces' => 0, 'totaltime' => 0]),
            'umami.example.com/api/websites/site-1/active' => Http::response(['visitors' => 0]),
            'umami.example.com/api/websites/site-1/pageviews*' => Http::response(['pageviews' => [], 'sessions' => []]),
            'umami.example.com/api/websites/site-1/metrics/expanded*' => Http::response([['name' => '5251806', 'pageviews' => 9, 'visitors' => 5, 'visits' => 6, 'bounces' => 0, 'totaltime' => 0]]),
        ]);

        $this->actingAs($this->member)->get(route('connectors.umami.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('sources', fn (Assert $reload) => $reload
                ->where('sources.utmCampaign.0.label', 'Black Friday')
                ->missing('sources.referrer.0.label')));
    });

    it('says why when Umami cannot be read, asking nothing more of it', function () {
        Http::fake(['umami.example.com/*' => Http::response(['error' => []], 401)]);

        $this->actingAs($this->member)->get(route('connectors.umami.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('error', __('connectors.umami.errors.unauthorized'))->where('summary', null)->where('active', null));

        Http::assertSentCount(1);
    });
});
