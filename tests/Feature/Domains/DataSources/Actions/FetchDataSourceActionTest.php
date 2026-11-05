<?php

declare(strict_types=1);

use App\Domains\DataSources\Actions\DispatchDueDataSourcesAction;
use App\Domains\DataSources\Actions\FetchDataSourceAction;
use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Jobs\FetchDataSource;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Support\Prometheus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Health\FakesHealthProbes;

covers(FetchDataSourceAction::class, Prometheus::class, DispatchDueDataSourcesAction::class, FetchDataSource::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    // The default, whatever the local .env says.
    config(['health.allow_private' => false]);
    $this->fakeDns();
    $this->fetch = function (array $attributes = []): DataSource {
        $source = DataSource::factory()->create($attributes);
        app(FetchDataSourceAction::class)->execute($source);

        return $source->refresh();
    };
});

describe('fetching', function () {
    it('keeps the latest body and the parsed body in the history, sending its headers', function () {
        Http::fake(['https://example.com/stats.json' => Http::response(['orders' => 12])]);

        $source = ($this->fetch)(['headers' => [['name' => 'Authorization', 'value' => 'Bearer s3cret']]]);

        expect($source->last_error)->toBeNull()
            ->and($source->last_status_code)->toBe(200)
            ->and($source->last_ok_at)->not->toBeNull()
            ->and(json_decode((string) $source->last_body, true))->toBe(['orders' => 12])
            ->and(DB::table('data_source_fetches')->where('data_source_id', $source->id)->count())->toBe(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer s3cret') && $request->method() === 'GET');
    });

    it('fails on an error status, a body that is not JSON or one too large, keeping the last good body', function (mixed $body, int $status, string $error) {
        Http::fake(['*' => Http::response($body, $status)]);

        $source = ($this->fetch)(['last_body' => '{"orders":1}']);

        expect($source->last_error)->toContain($error)
            ->and($source->last_body)->toBe('{"orders":1}')
            ->and(DB::table('data_source_fetches')->count())->toBe(0);
    })->with([
        'error status' => ['{}', 503, 'HTTP 503'],
        'not JSON' => ['<html>', 200, 'not valid JSON'],
        'too large' => ['"'.str_repeat('x', FetchDataSourceAction::MAX_BODY_BYTES).'"', 200, 'larger than'],
    ]);

    it('keeps a body too large for the history only as the latest', function () {
        Http::fake(['*' => Http::response('"'.str_repeat('x', FetchDataSourceAction::HISTORY_MAX_BYTES).'"')]);

        $source = ($this->fetch)();

        expect($source->last_error)->toBeNull()
            ->and(DB::table('data_source_fetches')->count())->toBe(0);
    });

    it('waits no longer than a health check may', function () {
        $options = null;
        Http::fake(function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('{}');
        });

        ($this->fetch)(['timeout_s' => 60]);

        expect($options['timeout'])->toBe(15);
    });

    it('keeps a body with \\u0000 in the history without it, as jsonb takes none', function () {
        Http::fake(['*' => Http::response('{"name":"a\\u0000b","path":"C:\\\\u0000"}')]);

        $source = ($this->fetch)();

        expect($source->last_error)->toBeNull()
            ->and(json_decode((string) DB::table('data_source_fetches')->where('data_source_id', $source->id)->value('body'), true))->toBe(['name' => 'ab', 'path' => 'C:\\u0000']);
    });

    it('keeps the URL out of the error it records, as its query can hold a key', function () {
        Http::fake(['*' => Http::failedConnection()]);

        $source = ($this->fetch)(['url' => 'https://example.com/stats.json?token=s3cret']);

        expect($source->last_error)->toBe('cURL error 6: Could not resolve host: example.com');
    });

    it('records headers it cannot decrypt as the error of that source alone', function () {
        Http::fake(['*' => Http::response('{}')]);
        $broken = DataSource::factory()->create(['headers' => [['name' => 'X-Api-Key', 'value' => 's3cret']]]);
        DB::table('data_sources')->where('id', $broken->id)->update(['headers' => 'not encrypted under this key']);
        $fine = DataSource::factory()->create();

        foreach ([$broken, $fine] as $source) {
            (new FetchDataSource(DataSource::query()->findOrFail($source->id)))->handle(app(FetchDataSourceAction::class));
        }

        expect($broken->refresh()->last_error)->toContain('cannot be decrypted')
            ->and($fine->refresh()->last_error)->toBeNull()
            ->and($fine->last_ok_at)->not->toBeNull();
    });
});

describe('the connection', function () {
    it('pins the vetted address through curl, never streaming, which would drop the pin', function () {
        $options = null;
        Http::fake(function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('{}');
        });

        ($this->fetch)();

        expect($options['curl'][CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.215.14'])
            ->and($options)->not->toHaveKey('stream')
            ->and($options['sink'])->toBeResource();
    });
});

describe('the private address guard', function () {
    it('refuses hosts on this server or the network behind it', function (string $url, array $addresses) {
        Http::fake();
        $this->fakeDns($addresses);

        $source = ($this->fetch)(['url' => $url]);

        expect($source->last_error)->toContain('private or reserved')
            ->and($source->last_ok_at)->toBeNull();
        Http::assertNothingSent();
    })->with([
        'loopback' => ['http://127.0.0.1/stats', []],
        'metadata service' => ['http://169.254.169.254/latest', []],
        'private network' => ['https://intranet.example.com/stats', ['10.0.0.5']],
    ]);

    it('checks a redirect target again', function () {
        Http::fake(['https://example.com/stats.json' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);

        expect(($this->fetch)()->last_error)->toContain('127.0.0.1 resolves to a private');
        Http::assertSentCount(1);
    });

    it('sends its headers only to its own origin on a redirect', function () {
        Http::fake([
            'https://example.com/stats.json' => Http::response('', 302, ['Location' => 'https://elsewhere.example.net/stats.json']),
            'https://elsewhere.example.net/*' => Http::response('{"ok":true}'),
        ]);

        $source = ($this->fetch)(['headers' => [['name' => 'X-Api-Key', 'value' => 's3cret']]]);

        expect($source->last_error)->toBeNull();
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'elsewhere') && ! $request->hasHeader('X-Api-Key'));
    });

    it('lets internal sources through when the self-hoster allows them', function () {
        config(['health.allow_private' => true]);
        Http::fake(['*' => Http::response('{}')]);

        expect(($this->fetch)(['url' => 'http://127.0.0.1/stats'])->last_error)->toBeNull();
    });
});

describe('the schedule', function () {
    it('queues the sources whose interval has come and moves their next fetch on', function () {
        Queue::fake();
        $this->travelTo(now()->setTime(12, 0, 30));
        $new = DataSource::factory()->create(['interval_minutes' => 5]);
        $due = DataSource::factory()->create(['interval_minutes' => 1, 'next_fetch_at' => now()->subMinute()]);
        DataSource::factory()->create(['next_fetch_at' => now()->addMinutes(3)]);

        expect(app(DispatchDueDataSourcesAction::class)->execute())->toBe(2)
            ->and(app(DispatchDueDataSourcesAction::class)->execute())->toBe(0)
            ->and($new->refresh()->next_fetch_at?->equalTo(now()->startOfMinute()->addMinutes(5)))->toBeTrue()
            ->and($due->refresh()->next_fetch_at?->equalTo(now()->startOfMinute()->addMinute()))->toBeTrue();

        $this->travel(1)->minutes();
        expect(app(DispatchDueDataSourcesAction::class)->execute())->toBe(1);

        Queue::assertPushedOn('health', FetchDataSource::class);
    });

    it('reads only what the claim needs, never the headers', function () {
        Queue::fake();
        DataSource::factory()->create(['headers' => [['name' => 'X-Api-Key', 'value' => 's3cret']]]);
        DB::enableQueryLog();

        app(DispatchDueDataSourcesAction::class)->execute();

        expect(DB::getQueryLog()[0]['query'])->toStartWith('select "id", "interval_minutes" from "data_sources"');
    });

    it('queues none another scheduler claimed first', function () {
        Queue::fake();
        $taken = DataSource::factory()->create();
        $free = DataSource::factory()->create();
        // Another scheduler moves it on between this one reading it and claiming it.
        DataSource::retrieved(function (DataSource $source) use ($taken): void {
            DB::table('data_sources')->where('id', $taken->id)->update(['next_fetch_at' => now()->addMinutes(5)]);
        });

        expect(app(DispatchDueDataSourcesAction::class)->execute())->toBe(1);

        Queue::assertPushed(FetchDataSource::class, 1);
        Queue::assertPushed(FetchDataSource::class, fn (FetchDataSource $job): bool => $job->source->is($free));
    });
});

describe('a Prometheus source', function () {
    it('keeps the raw text as the latest body and the parsed samples in the history, asking for the text format', function () {
        // jsonb keeps its own key order, so the shape is compared without it.
        $text = "# TYPE jobs_total counter\njobs_total{queue=\"mail\"} 12\n# TYPE depth gauge\ndepth 3\n";
        Http::fake(['*' => Http::response($text, 200, ['Content-Type' => 'text/plain; version=0.0.4'])]);

        $source = ($this->fetch)(['format' => DataSourceFormat::Prometheus]);

        expect($source->last_error)->toBeNull()
            ->and($source->last_body)->toBe($text)
            ->and(json_decode((string) DB::table('data_source_fetches')->value('body'), true))->toEqual(['families' => [
                'jobs_total' => ['type' => 'counter', 'samples' => [['labels' => ['queue' => 'mail'], 'value' => 12]]],
                'depth' => ['type' => 'gauge', 'samples' => [['labels' => [], 'value' => 3]]],
            ]]);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('Accept')[0], 'text/plain'));
    });

    it('fails on a body without samples, JSON too, keeping the last good body', function () {
        Http::fake(['*' => Http::response('{"orders": 1}')]);

        $source = ($this->fetch)(['format' => DataSourceFormat::Prometheus, 'last_body' => 'up 1']);

        expect($source->last_error)->toContain('no Prometheus samples')
            ->and($source->last_body)->toBe('up 1')
            ->and(DB::table('data_source_fetches')->count())->toBe(0);
    });

    it('keeps text with NUL bytes and invalid UTF-8, without them', function () {
        Http::fake(['*' => Http::response("up{a=\"x\0\xff\"} 1\n")]);

        $source = ($this->fetch)(['format' => DataSourceFormat::Prometheus]);

        expect($source->last_error)->toBeNull()
            ->and($source->last_body)->toBe("up{a=\"x?\"} 1\n")
            ->and(DB::table('data_source_fetches')->count())->toBe(1);
    });

    it('keeps parsed samples too large for the history only as the latest', function () {
        Http::fake(['*' => Http::response(implode("\n", array_map(fn (int $i): string => "m{series=\"{$i}\"} {$i}", range(1, 3000))))]);

        $source = ($this->fetch)(['format' => DataSourceFormat::Prometheus]);

        expect($source->last_error)->toBeNull()
            ->and(DB::table('data_source_fetches')->count())->toBe(0);
    });
});

describe('an iCalendar source', function () {
    beforeEach(function () {
        $this->feed = (string) file_get_contents(base_path('tests/Fixtures/DataSources/calendar.ics'));
        $this->fetchCalendar = function (?string $lastFeed = null): DataSource {
            $source = DataSource::factory()->ical($lastFeed)->create();
            app(FetchDataSourceAction::class)->execute($source);

            return $source->refresh();
        };
    });

    it('keeps the feed as the latest body only, asking for a calendar', function () {
        Http::fake(['*' => Http::response($this->feed, 200, ['Content-Type' => 'text/calendar'])]);

        $source = ($this->fetchCalendar)();

        expect($source->last_error)->toBeNull()
            ->and($source->last_body)->toBe($this->feed)
            ->and(DB::table('data_source_fetches')->count())->toBe(0);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('Accept')[0], 'text/calendar'));
    });

    it('fails on what is not a feed, keeping the last good one', function () {
        Http::fake(['*' => Http::response('<html><body>Sign in to Google Calendar</body></html>')]);

        $source = ($this->fetchCalendar)($this->feed);

        expect($source->last_error)->toContain('not an iCalendar feed')
            ->and($source->last_body)->toBe($this->feed);
    });

    it('reads a feed larger than other sources may be', function () {
        $event = "BEGIN:VEVENT\r\nUID:%d@example.com\r\nSUMMARY:".str_repeat('x', 200)."\r\nDTSTART:20260101T090000Z\r\nEND:VEVENT\r\n";
        $large = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n".implode('', array_map(fn (int $i): string => sprintf($event, $i), range(1, 6000)))."END:VCALENDAR\r\n";
        Http::fake(['*' => Http::response($large)]);

        $source = ($this->fetchCalendar)();

        expect(strlen($large))->toBeGreaterThan(FetchDataSourceAction::MAX_BODY_BYTES)
            ->and($source->last_error)->toBeNull();
    });
});
