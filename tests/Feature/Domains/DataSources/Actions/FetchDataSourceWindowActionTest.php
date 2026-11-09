<?php

declare(strict_types=1);

use App\Domains\DataSources\Actions\DispatchDueDataSourceWindowsAction;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Actions\SendSignedPullAction;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Jobs\FetchDataSourceWindow;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\DataSources\Support\PullSignature;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Health\FakesHealthProbes;

covers(SendSignedPullAction::class, FetchDataSourceWindowAction::class, DispatchDueDataSourceWindowsAction::class, FetchDataSourceWindow::class, PullSignature::class, DataSourceShape::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.allow_private' => false]);
    $this->fakeDns();
    $this->project = Project::factory()->create();
    $this->source = DataSource::factory()->for($this->project)->connected()->create();
    $this->window = DataSourceWindow::factory()->forSource($this->source)->create();
    $this->url = 'https://app.example.com/lumberjack/data-sources*';
    $this->pull = function (): DataSourceWindow {
        app(FetchDataSourceWindowAction::class)->execute($this->window->id);

        return $this->window->refresh();
    };
});

describe('the signature', function () {
    it('matches the format the Laravel package verifies', function () {
        expect(PullSignature::sign('lj_pull_test', 'GET', 'App.Example.com', '/lumberjack/data-sources', ['to' => 2000, 'only' => 'a,b', 'from' => 1000], 1762700000))
            ->toBe('v1=5993b30d24afe96048e16cceab551cd866b7e625357ef76f61d629eeb9e22d2d');
    });

    it('signs the call it sends, over a range of whole steps, never sending the token', function () {
        Http::fake([$this->url => Http::response(['version' => 1, 'sources' => ['orders:total' => ['shape' => 'number', 'data' => 42]]])]);

        ($this->pull)();

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && (int) $query['to'] % 300 === 0
                && (int) $query['to'] - (int) $query['from'] === 86400
                && $query['only'] === 'orders:total'
                && ! str_contains($request->url().json_encode($request->headers()), 'lj_pull_test')
                && $request->header('X-Lumberjack-Signature')[0] === PullSignature::sign('lj_pull_test', 'GET', 'app.example.com', '/lumberjack/data-sources', $query, (int) $request->header('X-Lumberjack-Timestamp')[0]);
        });
    });

    it('uses the previous token while the app still holds it', function () {
        $this->source->connection->update(['pull_token' => 'lj_pull_new', 'pull_token_previous' => 'lj_pull_test', 'uses_previous' => true]);
        Http::fake([$this->url => Http::response(['sources' => ['orders:total' => ['shape' => 'number', 'data' => 1]]])]);

        expect(($this->pull)()->has_data)->toBeTrue();
    });
});

describe('an answer', function () {
    it('keeps the data and marks the source healthy', function () {
        Http::fake([$this->url => Http::response(['sources' => ['orders:total' => ['shape' => 'number', 'data' => 42]]])]);

        $window = ($this->pull)();

        expect($window->data)->toBe(42)->and($window->error)->toBeNull()
            ->and($this->source->refresh()->last_ok_at)->not->toBeNull();
    });

    it('keeps what it had when a later pull fails', function () {
        $this->window->update(['data' => 7, 'has_data' => true, 'fetched_at' => $fetched = now()->subDays(3)]);
        Http::fake([$this->url => Http::response('', 404)]);

        $window = ($this->pull)();

        expect($window->data)->toBe(7)->and($window->error)->toContain('404')
            ->and($window->fetched_at->getTimestamp())->toBe($fetched->getTimestamp());
    });

    it('refuses what is not the shape the source declares', function (array $answer) {
        Http::fake([$this->url => Http::response(['sources' => ['orders:total' => $answer]])]);

        $window = ($this->pull)();

        expect($window->has_data)->toBeFalse()->and($window->error)->toBe(__('data_sources.errors.bad_shape'));
    })->with([
        'another shape' => [['shape' => 'series', 'data' => []]],
        'text for a number' => [['shape' => 'number', 'data' => 'ten']],
        'no data at all' => [['shape' => 'number']],
    ]);

    it('rebuilds a series from its known fields and refuses too many points', function () {
        $this->source->update(['shape' => DataSourceShape::Series]);
        Http::fake([$this->url => Http::sequence()
            ->push(['sources' => ['orders:total' => ['shape' => 'series', 'data' => [['at' => 1000, 'value' => 1.5, 'secret' => 'x']]]]])
            ->push(['sources' => ['orders:total' => ['shape' => 'series', 'data' => array_fill(0, 1001, ['at' => 1, 'value' => 1])]]])]);

        expect(($this->pull)()->data)->toBe([['at' => 1000, 'value' => 1.5]])
            ->and(($this->pull)()->error)->toBe(__('data_sources.errors.bad_shape'));
    });
});

describe('the range', function () {
    it('is rounded to the cache of the app, so that its cache is hit', function () {
        $this->source->update(['cache_seconds' => 3600]);
        Http::fake([$this->url => Http::response(['sources' => ['orders:total' => ['shape' => 'number', 'data' => 1]]])]);

        ($this->pull)();

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) $query['to'] % 3600 === 0 && (int) $query['to'] - (int) $query['from'] === 86400;
        });
    });

    it('tells the app the resolution of the period and the time zone of the connection', function () {
        $this->source->connection->update(['timezone' => 'Europe/Copenhagen']);
        Http::fake(['*' => Http::response(['version' => 1, 'sources' => ['orders:total' => ['shape' => 'number', 'data' => 1]]])]);

        app(FetchDataSourceWindowAction::class)->execute(DataSourceWindow::factory()->for($this->source, 'source')->create(['period' => '30d', 'bucket' => 86400])->id);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['bucket'] === '86400' && $query['timezone'] === 'Europe/Copenhagen';
        });
    });

    it('asks the app again when it has moved on from the old token', function () {
        $this->source->connection->update(['pull_token' => 'lj_pull_new', 'pull_token_previous' => 'lj_pull_test', 'uses_previous' => true, 'next_check_at' => now()->addMinutes(4)]);
        Http::fake([$this->url => Http::response('', 404)]);

        ($this->pull)();

        expect($this->source->connection->refresh()->next_check_at)->toBeNull();
    });

    it('tries again soon after a failure, however long the app caches', function () {
        $this->source->update(['cache_seconds' => 3600]);
        Http::fake([$this->url => Http::response('', 500)]);

        expect(($this->pull)()->next_fetch_at->lte(now()->addMinutes(5)))->toBeTrue();
    });
});

describe('an answer that could break the job', function () {
    it('backs off an app that keeps failing, and starts over once it answers', function () {
        DataSourceWindow::query()->update(['failures' => 3]);
        Http::fake(['*' => Http::response('', 500)]);

        expect(($this->pull)())->failures->toBe(4)
            ->next_fetch_at->gte(now()->addMinutes(39))->toBeTrue();
    });

    it('refuses a number JSON cannot hold, and keeps going', function () {
        Http::fake([$this->url => Http::response('{"sources":{"orders:total":{"shape":"number","data":1e999}}}')]);

        expect(($this->pull)()->error)->toBe(__('data_sources.errors.bad_shape'));
    });

    it('stores an error with a NUL in it', function () {
        Http::fake([$this->url => Http::response(['sources' => ['orders:total' => ['error' => "bad\0thing"]]])]);

        expect(($this->pull)()->error)->toContain('badthing');
    });

    it('refuses more than it reads', function () {
        Http::fake([$this->url => Http::response(str_repeat('x', SendSignedPullAction::MAX_BODY_BYTES + 10))]);

        expect(($this->pull)()->error)->toContain('larger');
    });
});

describe('what is never pulled', function () {
    it('makes no call to an address that is not https', function (array $source, string $error) {
        Http::fake();
        $this->source->update($source);

        expect(($this->pull)()->error)->toBe(__($error));
        Http::assertNothingSent();
    })->with([
        'plain http' => [['url' => 'http://app.example.com/lumberjack/data-sources'], 'data_sources.errors.not_https'],
    ]);

    it('does not follow a redirect', function () {
        Http::fake([$this->url => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/'])]);

        expect(($this->pull)()->error)->toContain('302');
        Http::assertSentCount(1);
    });

    it('makes no call to a private address, even when health checks may', function () {
        config(['health.allow_private' => true]);
        Http::fake();
        $this->source->update(['url' => 'https://10.0.0.5/lumberjack/data-sources']);

        expect(($this->pull)()->error)->not->toBeNull();
        Http::assertNothingSent();
    });

    it('says so when a token was saved under another APP_KEY', function () {
        Http::fake();
        DB::table('data_source_connections')->where('id', $this->source->data_source_connection_id)->update(['pull_token' => 'not-encrypted']);

        expect(($this->pull)()->error)->toBe(__('data_sources.errors.token_unreadable'));
    });
});

describe('queueing pulls', function () {
    beforeEach(fn () => Queue::fake());

    it('queues one pull per source and period, once', function () {
        $other = DataSource::factory()->for($this->project)->connected('orders:today')->create();
        DataSourceWindow::factory()->forSource($other)->create();

        expect(app(DispatchDueDataSourceWindowsAction::class)->execute())->toBe(2)
            ->and(app(DispatchDueDataSourceWindowsAction::class)->execute())->toBe(0);
        Queue::assertPushed(FetchDataSourceWindow::class, 2);
    });

    it('waits as long as the app caches, within what the period allows', function (?int $cache, string $period, int $step) {
        $this->source->update(['cache_seconds' => $cache]);

        expect(FetchDataSourceWindowAction::stepSeconds($this->source, Period::from($period)))->toBe($step);
    })->with([
        'no cache' => [null, '24h', 300],
        'a short cache' => [60, '24h', 300],
        'an hour' => [3600, '24h', 3600],
        'longer than the period' => [86400, '1h', 3600],
        'longer than a day' => [604800, '30d', 86400],
    ]);

    it('does not pull what no screen asked for lately, and forgets it after a day', function () {
        $this->window->update(['requested_at' => now()->subMinutes(13)]);

        expect(app(DispatchDueDataSourceWindowsAction::class)->execute())->toBe(0);

        $this->window->update(['requested_at' => now()->subDays(2)]);
        app(DispatchDueDataSourceWindowsAction::class)->execute();

        expect(DataSourceWindow::query()->count())->toBe(0);
    });

    it('does not pull an archived source', function () {
        $this->source->update(['archived_at' => now()]);

        expect(app(DispatchDueDataSourceWindowsAction::class)->execute())->toBe(0);
    });
});

it('queues only so many pulls per app at once, and tries a dead pull again soon', function () {
    Queue::fake();
    DataSourceWindow::query()->delete();
    $connection = $this->source->data_source_connection_id;

    foreach (range(1, 6) as $i) {
        $source = DataSource::factory()->for($this->project)->connected()->create(['key' => "s{$i}", 'data_source_connection_id' => $connection]);
        DataSourceWindow::factory()->forSource($source)->create(['requested_at' => now(), 'next_fetch_at' => null]);
    }

    expect(app(DispatchDueDataSourceWindowsAction::class)->execute())->toBe(4);

    $window = DataSourceWindow::query()->whereNotNull('next_fetch_at')->firstOrFail();
    (new FetchDataSourceWindow($window->id))->failed();

    expect($window->refresh()->next_fetch_at->lte(now()->addMinutes(5)))->toBeTrue();
});
