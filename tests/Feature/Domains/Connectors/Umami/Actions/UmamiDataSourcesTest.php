<?php

declare(strict_types=1);

use App\Domains\Connectors\Umami\Actions\BuildUmamiCatalogAction;
use App\Domains\Connectors\Umami\Actions\PullUmamiSourceAction;
use App\Domains\Connectors\Umami\Enums\UmamiSource;
use App\Domains\Connectors\Umami\Enums\UtmDimension;
use App\Domains\Connectors\Umami\Models\UtmLabel;
use App\Domains\Connectors\Umami\Support\UmamiSeries;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Health\FakesHealthProbes;

covers(BuildUmamiCatalogAction::class, PullUmamiSourceAction::class, UmamiSeries::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.data_sources_allow_private' => false]);
    $this->fakeDns();
    $this->travelTo('2026-11-10 12:00:00');
    $this->connection = DataSourceConnection::factory()->umami()->create(['timezone' => 'UTC']);
    // What /metrics answers, by the breakdown asked for.
    $this->metrics = ['event' => [['x' => 'host:paid', 'y' => 4]], 'utmCampaign' => [['x' => '5251806', 'y' => 9]]];
    Http::fake(['umami.example.com/api/websites/site-1/metrics*' => fn (Request $request) => Http::response($this->metrics[$request['type']] ?? [])]);
    $this->sync = function (): DataSourceConnection {
        app(SyncDataSourceConnectionAction::class)->execute($this->connection->id);

        return $this->connection->refresh();
    };
    $this->pull = function (string $key, string $period = '24h'): DataSourceWindow {
        $source = DataSource::query()->where('key', $key)->sole();
        $window = DataSourceWindow::query()->create(['data_source_id' => $source->id, 'period' => $period, 'bucket' => $period === '24h' ? 3600 : 86400, 'requested_at' => now()]);
        app(FetchDataSourceWindowAction::class)->execute($window->id);

        return $window->refresh();
    };
    $this->event = fn (string $name): string => BuildUmamiCatalogAction::key(UmamiSource::EVENT_PREFIX, $name);
    $this->campaign = fn (string $value): string => BuildUmamiCatalogAction::key(UmamiSource::CAMPAIGN_PREFIX, $value);
});

describe('listing an Umami website\'s sources', function () {
    it('offers the fixed sources and a count and series for each event seen lately', function () {
        $this->metrics['event'] = [['x' => 'host:paid', 'y' => 4], ['x' => 'Signed up!', 'y' => 2]];

        $connection = ($this->sync)();
        $keys = DataSource::query()->pluck('name', 'key');

        expect($connection->last_error)->toBeNull()
            ->and($keys)->toHaveKeys(['visitors', 'bounce_rate', 'visitors.series', 'pages', ($this->event)('host:paid'), ($this->event)('Signed up!').'.series'])
            ->and($keys[($this->event)('host:paid')])->toBe('host:paid')
            ->and($keys['visitors.series'])->toBe('Visitors over time')
            ->and(DataSource::query()->where('key', 'visitors')->value('group_path'))->toBeNull();
    });

    it('offers each campaign seen lately or given a name, under its name, kept by its value', function () {
        UtmLabel::factory()->for($this->connection, 'dataSourceConnection')->create(['value' => '5251806', 'label' => 'Black Friday']);
        UtmLabel::factory()->for($this->connection, 'dataSourceConnection')->create(['value' => 'quiet', 'label' => 'Spring']);

        ($this->sync)();

        expect(DataSource::query()->where('key', ($this->campaign)('5251806'))->first(['name', 'filter_value', 'group_path'])->toArray())
            ->toBe(['name' => 'Black Friday', 'filter_value' => '5251806', 'group_path' => 'Campaigns'])
            ->and(DataSource::query()->where('key', ($this->campaign)('quiet').'.series')->value('name'))->toBe('Spring over time');
    });

    it('shows why Umami could not be asked on the connection', function () {
        $connection = DataSourceConnection::factory()->umami('site-2')->create();
        Http::fake(['umami.example.com/api/websites/site-2/*' => Http::response(['error' => ['code' => 'unauthorized']], 401)]);

        app(SyncDataSourceConnectionAction::class)->execute($connection->id);

        expect($connection->refresh()->last_error)->toBe(__('connectors.umami.errors.unauthorized'));
    });
});

describe('pulling a source for a screen', function () {
    beforeEach(function () {
        ($this->sync)();
    });

    it('makes a rate out of the totals', function () {
        Http::fake(['umami.example.com/api/websites/site-1/stats*' => Http::response(['visitors' => 8, 'visits' => 10, 'pageviews' => 20, 'bounces' => 4, 'totaltime' => 900])]);

        expect(($this->pull)('bounce_rate')->data)->toBe(40)
            ->and(($this->pull)('visit_duration')->data)->toBe(90);
    });

    it('adds Umami\'s hours up into the buckets of the period, every bucket present', function () {
        Http::fake(['umami.example.com/api/websites/site-1/pageviews*' => Http::response([
            'pageviews' => [['x' => '2026-11-10 09:00:00', 'y' => 5]],
            'sessions' => [['x' => '2026-11-10 09:00:00', 'y' => 3], ['x' => '2026-11-10 10:00:00', 'y' => 2]],
        ])]);

        $data = ($this->pull)('visitors.series')->data;

        expect($data)->toHaveCount(24)
            ->and(collect($data)->firstWhere('at', strtotime('2026-11-10 09:00:00 UTC'))['value'])->toBe(3)
            ->and(collect($data)->sum('value'))->toBe(5);
    });

    it('reads Umami\'s times as the wall clock of the connection\'s zone, whatever zone they claim', function () {
        $this->connection->update(['timezone' => 'Europe/Copenhagen']);
        Http::fake(['umami.example.com/api/websites/site-1/pageviews*' => Http::response([
            'pageviews' => [],
            'sessions' => [['x' => '2026-11-10T07:00:00Z', 'y' => 3]],
        ])]);

        $data = ($this->pull)('visitors.series')->data;

        expect(collect($data)->firstWhere('at', strtotime('2026-11-10 06:00:00 UTC'))['value'])->toBe(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'timezone=Europe%2FCopenhagen'));
    });

    it('reads a custom event\'s count by its name', function () {
        $this->metrics['event'] = [['x' => 'host:paid', 'y' => 7]];

        expect(($this->pull)(($this->event)('host:paid'))->data)->toBe(7);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'event=host%3Apaid'));
    });

    it('counts a campaign\'s visitors by its value, whatever it is named', function () {
        Http::fake(['umami.example.com/api/websites/site-1/stats*' => Http::response(['visitors' => 31, 'visits' => 40, 'pageviews' => 90, 'bounces' => 0, 'totaltime' => 0])]);

        expect(($this->pull)(($this->campaign)('5251806'))->data)->toBe(31);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stats') && str_contains($request->url(), 'utmCampaign=5251806'));
    });

    it('lists a breakdown, naming the empty value', function () {
        $this->metrics['referrer'] = [['x' => null, 'y' => 9], ['x' => 'google.com', 'y' => 4]];

        expect(($this->pull)('referrers')->data)->toBe([['label' => __('connectors.umami.none'), 'value' => 9], ['label' => 'google.com', 'value' => 4]]);
    });

    it('lists UTM values by the names they were given', function () {
        UtmLabel::factory()->for($this->connection, 'dataSourceConnection')->create(['dimension' => UtmDimension::Campaign, 'value' => '5251806', 'label' => 'Black Friday']);

        expect(($this->pull)('utm_campaigns')->data)->toBe([['label' => 'Black Friday', 'value' => 9]]);
    });
});
