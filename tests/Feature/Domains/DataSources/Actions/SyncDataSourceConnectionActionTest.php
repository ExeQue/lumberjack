<?php

declare(strict_types=1);

use App\Domains\DataSources\Actions\DispatchDueDataSourceConnectionsAction;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Jobs\SyncDataSourceConnection;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Support\PullSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Health\FakesHealthProbes;

covers(SyncDataSourceConnectionAction::class, DispatchDueDataSourceConnectionsAction::class, SyncDataSourceConnection::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.allow_private' => false]);
    $this->fakeDns();
    $this->connection = DataSourceConnection::factory()->create();
    $this->url = 'https://app.example.com/lumberjack/data-sources';
    $this->index = ['version' => 1, 'complete' => true, 'sources' => [['key' => 'orders:total', 'label' => 'Orders', 'shape' => 'number', 'cache' => 300]]];
    $this->sync = function (): DataSourceConnection {
        app(SyncDataSourceConnectionAction::class)->execute($this->connection->id);

        return $this->connection->refresh();
    };
});

describe('asking an app', function () {
    it('lists its sources, signing a call with no query, the token never sent', function () {
        Http::fake([$this->url => Http::response($this->index)]);

        $connection = ($this->sync)();

        expect($connection->last_ok_at)->not->toBeNull()->and($connection->last_error)->toBeNull()
            ->and(DataSource::query()->sole()->key)->toBe('orders:total');
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->url
            && ! str_contains(json_encode($request->headers()), 'lj_pull_test')
            && $request->header('X-Lumberjack-Signature')[0] === PullSignature::sign('lj_pull_test', 'GET', 'app.example.com', '/lumberjack/data-sources', [], (int) $request->header('X-Lumberjack-Timestamp')[0]));
    });

    it('says so when the app offers more than fits, keeping what does', function () {
        $sources = array_map(fn (int $i): array => ['key' => 'k'.$i, 'label' => 'x', 'shape' => 'number'], range(1, 201));
        Http::fake([$this->url => Http::response([...$this->index, 'sources' => $sources])]);

        expect(($this->sync)()->last_error)->toContain('left out')
            ->and(DataSource::query()->count())->toBe(200);
    });

    it('is waiting, not ok, while the app answers 404', function () {
        Http::fake([$this->url => Http::response('', 404)]);

        $connection = ($this->sync)();

        expect($connection->last_ok_at)->toBeNull()->and($connection->last_error)->toBe(__('data_sources.errors.not_answering'));
    });

    it('records what it cannot read without the job failing', function (mixed $body) {
        Http::fake([$this->url => Http::response($body)]);

        expect(($this->sync)()->last_error)->not->toBeNull();
    })->with(['not json' => ['<html>'], 'a scalar' => ['42']]);
});

describe('a rotation', function () {
    beforeEach(fn () => $this->connection->update(['pull_token' => 'lj_pull_new', 'pull_token_previous' => 'lj_pull_test', 'uses_previous' => true]));

    $answersTo = fn (string $token) => fn (Request $request) => $request->header('X-Lumberjack-Signature')[0] === PullSignature::sign($token, 'GET', 'app.example.com', '/lumberjack/data-sources', [], (int) $request->header('X-Lumberjack-Timestamp')[0]);

    it('goes on with the old token until the app has the new one', function () use ($answersTo) {
        Http::fake(fn (Request $request) => $answersTo('lj_pull_test')($request) ? Http::response($this->index) : Http::response('', 404));

        $connection = ($this->sync)();

        expect($connection->uses_previous)->toBeTrue()->and($connection->activeToken())->toBe('lj_pull_test');
    });

    it('is not ended by a 200 that is no list of sources', function () use ($answersTo) {
        Http::fake(fn (Request $request) => $answersTo('lj_pull_new')($request) ? Http::response([]) : Http::response('', 404));

        $connection = ($this->sync)();

        expect($connection->uses_previous)->toBeTrue()->and($connection->pull_token_previous)->toBe('lj_pull_test')
            ->and($connection->last_error)->toBe(__('data_sources.errors.bad_index'));
    });

    it('forgets the old token at the first answer to the new one', function () use ($answersTo) {
        Http::fake(fn (Request $request) => $answersTo('lj_pull_new')($request) ? Http::response($this->index) : Http::response('', 404));

        $connection = ($this->sync)();

        expect($connection->uses_previous)->toBeFalse()->and($connection->pull_token_previous)->toBeNull();
    });
});

describe('what stops it', function () {
    it('says so when the token was saved under another APP_KEY', function () {
        Http::fake();
        DB::table('data_source_connections')->where('id', $this->connection->id)->update(['pull_token' => 'not-encrypted']);

        expect(($this->sync)()->last_error)->toBe(__('data_sources.errors.token_unreadable'));
        Http::assertNothingSent();
    });

    it('calls no private address, even when health checks may', function () {
        config(['health.allow_private' => true]);
        Http::fake();
        $this->connection->update(['url' => 'https://10.0.0.5/lumberjack/data-sources']);

        expect(($this->sync)()->last_error)->not->toBeNull();
        Http::assertNothingSent();
    });
});

describe('queueing', function () {
    it('asks each app once per round', function () {
        Queue::fake();

        expect(app(DispatchDueDataSourceConnectionsAction::class)->execute())->toBe(1)
            ->and(app(DispatchDueDataSourceConnectionsAction::class)->execute())->toBe(0);
        Queue::assertPushed(SyncDataSourceConnection::class, 1);
    });
});

it('counts an app that listed too much as having answered', function () {
    Http::fake(['*' => Http::response(['version' => 1, 'sources' => array_fill(0, 201, $this->index['sources'][0])])]);

    expect(($this->sync)())->last_ok_at->not->toBeNull()->last_error->not->toBeNull();
});
