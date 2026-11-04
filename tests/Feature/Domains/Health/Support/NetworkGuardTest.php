<?php

declare(strict_types=1);

use App\Domains\DataSources\Actions\FetchDataSourceAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Support\CappedStream;
use App\Domains\Health\Support\NetworkGuard;
use Illuminate\Support\Facades\Http;
use Tests\Support\Health\FakesHealthProbes;

covers(NetworkGuard::class, CappedStream::class);

uses(FakesHealthProbes::class);

// A real server on this machine, as only a real curl transfer shows what the guard pins and cuts off.
beforeAll(function () {
    $router = tempnam(sys_get_temp_dir(), 'router');
    file_put_contents($router, <<<'PHP'
        <?php
        if (str_starts_with($_SERVER['REQUEST_URI'], '/big')) {
            http_response_code(str_contains($_SERVER['REQUEST_URI'], 'error') ? 500 : 200);
            for ($i = 0; $i < 40; $i++) { echo str_repeat('x', 65536); flush(); }
            exit;
        }
        echo json_encode(['host' => $_SERVER['HTTP_HOST']]);
        PHP);
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $GLOBALS['guardTestPort'] = (int) parse_url('tcp://'.stream_socket_get_name($probe, false), PHP_URL_PORT);
    fclose($probe);
    $GLOBALS['guardTestServer'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$GLOBALS['guardTestPort'], $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $GLOBALS['guardTestPort']) === false; $i++) {
        usleep(100_000);
    }
});

afterAll(function () {
    proc_terminate($GLOBALS['guardTestServer']);
});

beforeEach(function () {
    Http::allowStrayRequests();
    // The server is on this machine; the host it is reached by resolves nowhere but through the guard.
    config(['health.allow_private' => true]);
    $this->fakeDns(['127.0.0.1']);
    $this->url = fn (string $path = '/'): string => 'http://pinned.invalid:'.$GLOBALS['guardTestPort'].$path;
});

describe('a request through the guard', function () {
    it('connects to the address it vetted, not to what a lookup of the host says', function () {
        $response = app(NetworkGuard::class)->send(Http::timeout(5), 'GET', ($this->url)(), 1000);

        expect($response->json('host'))->toBe('pinned.invalid:'.$GLOBALS['guardTestPort']);
    });

    it('stops reading a body past the most, keeping the status and the first bytes', function (string $path, int $status) {
        $response = app(NetworkGuard::class)->send(Http::timeout(5), 'GET', ($this->url)($path), 1000);

        expect($response->status())->toBe($status)
            ->and($response->body())->toBe(str_repeat('x', 1000));
    })->with([
        'ok' => ['/big', 200],
        'error status' => ['/big-error', 500],
    ]);

    it('fails a data source whose body is too large without reading it whole', function () {
        $source = DataSource::factory()->create(['url' => ($this->url)('/big')]);

        app(FetchDataSourceAction::class)->execute($source);

        expect($source->refresh()->last_error)->toContain('larger than')
            ->and($source->last_body)->toBeNull();
    });
});
