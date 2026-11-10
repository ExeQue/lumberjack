<?php

declare(strict_types=1);

use App\Domains\Connectors\Mosparo\Data\MosparoCredentials;
use App\Domains\Connectors\Mosparo\Exceptions\MosparoRequestFailedException;
use App\Domains\Connectors\Mosparo\Support\MosparoClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Health\FakesHealthProbes;

covers(MosparoClient::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.data_sources_allow_private' => false]);
    $this->fakeDns();
    $this->client = app(MosparoClient::class);
    $this->keys = new MosparoCredentials('https://mosparo.example.com', 'pub', 'priv');
});

describe('asking for the statistics', function () {
    it('signs the path and its arguments with the private key, as mosparo checks them', function (?int $range, string $signed) {
        Http::fake(['mosparo.example.com/*' => Http::response(['result' => true, 'data' => []])]);

        $this->client->statistics($this->keys, $range);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://mosparo.example.com/api/v1/statistic/by-date')
            && $request->header('Authorization')[0] === base64_encode('pub:'.hash_hmac('sha256', '/api/v1/statistic/by-date'.$signed, 'priv')));
    })->with([
        'everything kept' => [null, '{}'],
        'the last day' => [86400, '{"range":86400}'],
    ]);

    it('reads the totals and each day', function () {
        Http::fake(['mosparo.example.com/*' => Http::response(['result' => true, 'data' => [
            'numberOfValidSubmissions' => 5, 'numberOfSpamSubmissions' => 2, 'numberOfDelayedRequests' => 1, 'numberOfBlockedRequests' => 3,
            'lastSubmissionAt' => '2026-11-09T11:39:13+00:00',
            'numbersByDate' => ['2026-11-09' => ['numberOfValidSubmissions' => 4, 'numberOfSpamSubmissions' => 2], '2026-11-08' => ['numberOfValidSubmissions' => 1], 'nonsense' => []],
        ]])]);

        expect($this->client->statistics($this->keys))->toBe([
            'valid' => 5, 'spam' => 2, 'delayed' => 1, 'blocked' => 3,
            'last_submission_at' => '2026-11-09T11:39:13+00:00',
            'days' => [
                '2026-11-08' => ['valid' => 1, 'spam' => 0, 'delayed' => 0, 'blocked' => 0],
                '2026-11-09' => ['valid' => 4, 'spam' => 2, 'delayed' => 0, 'blocked' => 0],
            ],
        ]);
    });

    it('says the keys were refused', function () {
        Http::fake(['mosparo.example.com/*' => Http::response(['error' => true, 'errorMessage' => 'Request invalid.'], 400)]);

        $this->client->statistics($this->keys);
    })->throws(MosparoRequestFailedException::class, 'refused the keys');

    it('says so when the address is not mosparo', function () {
        Http::fake(['mosparo.example.com/*' => Http::response('<html></html>')]);

        $this->client->statistics($this->keys);
    })->throws(MosparoRequestFailedException::class, 'did not answer as mosparo');
});

describe('what it refuses to ask', function () {
    it('never sends the keys over plain http or to a private address', function (string $host) {
        Http::fake();

        expect(fn () => $this->client->statistics(new MosparoCredentials($host, 'pub', 'priv')))->toThrow(MosparoRequestFailedException::class);
        Http::assertNothingSent();
    })->with(['http://mosparo.example.com', 'https://10.0.0.5']);
});
