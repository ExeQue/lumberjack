<?php

declare(strict_types=1);

use App\Domains\Connectors\Umami\Data\UmamiCredentials;
use App\Domains\Connectors\Umami\Enums\UmamiHosting;
use App\Domains\Connectors\Umami\Exceptions\UmamiRequestFailedException;
use App\Domains\Connectors\Umami\Support\UmamiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Health\FakesHealthProbes;

covers(UmamiClient::class, UmamiHosting::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.data_sources_allow_private' => false]);
    $this->fakeDns();
    $this->client = app(UmamiClient::class);
    $this->login = new UmamiCredentials('https://umami.example.com/api', 'admin', 'hunter2');
});

describe('signing in', function () {
    it('sends a Cloud API key as a bearer token to the cloud API', function () {
        Http::fake([
            'api.umami.is/v1/me/teams*' => Http::response(['data' => []]),
            'api.umami.is/v1/websites*' => Http::response(['data' => [['id' => 'a', 'name' => 'Shop', 'domain' => 'shop.example']]]),
        ]);

        $websites = $this->client->websites(new UmamiCredentials(UmamiHosting::Cloud->apiUrl(null), null, 'key_123'));

        expect($websites)->toBe([['id' => 'a', 'name' => 'Shop', 'domain' => 'shop.example', 'team' => null]]);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.umami.is/v1/websites?')
            && $request->header('Authorization')[0] === 'Bearer key_123');
    });

    it('logs in to a self-hosted instance once, and signs with its token', function () {
        Http::fake([
            'umami.example.com/api/auth/login' => Http::response(['token' => 'jwt_1']),
            'umami.example.com/api/me/teams*' => Http::response(['data' => []]),
            'umami.example.com/api/websites*' => Http::response(['data' => [['id' => 'a', 'name' => 'Shop']]]),
        ]);

        $this->client->websites($this->login);
        $this->client->websites($this->login);

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://umami.example.com/api/auth/login'
            && $request['username'] === 'admin' && $request['password'] === 'hunter2');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/websites') && $request->header('Authorization')[0] === 'Bearer jwt_1');
    });

    it('logs in again once when Umami no longer takes the token', function () {
        Http::fake([
            'umami.example.com/api/auth/login' => Http::sequence()->push(['token' => 'old'])->push(['token' => 'new']),
            'umami.example.com/api/me/teams*' => Http::sequence()->push(['error' => []], 401)->push(['data' => []]),
            'umami.example.com/api/websites*' => Http::response(['data' => [['id' => 'a', 'name' => 'Shop']]]),
        ]);

        expect($this->client->websites($this->login))->toHaveCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/websites') && $request->header('Authorization')[0] === 'Bearer new');
    });

    it('asks for an API key when the user signs in with two factors', function () {
        Http::fake(['umami.example.com/api/auth/login' => Http::response(['requiresTwoFactor' => true, 'partialToken' => 'p'])]);

        $this->client->websites($this->login);
    })->throws(UmamiRequestFailedException::class, 'two-factor');

    it('says the credentials were refused', function () {
        Http::fake(['umami.example.com/api/auth/login' => Http::response(['error' => ['code' => 'unauthorized']], 401)]);

        $this->client->websites($this->login);
    })->throws(UmamiRequestFailedException::class, 'refused the credentials');
});

describe('listing the websites', function () {
    beforeEach(function () {
        $this->key = new UmamiCredentials('https://umami.example.com/api', null, 'k');
    });

    it('always lists the user\'s own websites and each team\'s, filed under the team', function () {
        Http::fake([
            'umami.example.com/api/me/teams*' => Http::response(['data' => [['id' => 't1', 'name' => 'Marketing']]]),
            'umami.example.com/api/teams/t1/websites*' => Http::response(['data' => [['id' => 'b', 'name' => 'Blog'], ['id' => 'a', 'name' => 'Shop']]]),
            'umami.example.com/api/websites*' => Http::response(['data' => [['id' => 'a', 'name' => 'Shop'], ['id' => 'c', 'name' => 'Own']]]),
        ]);

        expect(collect($this->client->websites($this->key))->pluck('team', 'id')->all())->toBe(['b' => 'Marketing', 'a' => 'Marketing', 'c' => null]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/admin/'));
    });

    it('lists every website for an administrator who has none of their own', function () {
        Http::fake([
            'umami.example.com/api/me/teams*' => Http::response(['data' => []]),
            'umami.example.com/api/websites*' => Http::response(['data' => []]),
            'umami.example.com/api/admin/websites*' => Http::response(['data' => [['id' => 'x', 'name' => 'Someone else\'s']]]),
        ]);

        expect($this->client->websites($this->key))->toBe([['id' => 'x', 'name' => 'Someone else\'s', 'domain' => null, 'team' => null]]);
    });

    it('lists the user\'s own websites when Umami sends the teams elsewhere', function () {
        Http::fake([
            'umami.example.com/api/me/teams*' => Http::response('', 302, ['Location' => 'https://umami.example.com/login']),
            'umami.example.com/api/websites*' => Http::response(['data' => [['id' => 'a', 'name' => 'Shop']]]),
        ]);

        expect($this->client->websites($this->key))->toHaveCount(1);
    });

    it('says so rather than listing nothing when the answer is not a listing', function () {
        Http::fake([
            'umami.example.com/api/me/teams*' => Http::response(['data' => []]),
            'umami.example.com/api/websites*' => Http::response(['unexpected' => true]),
        ]);

        $this->client->websites($this->key);
    })->throws(UmamiRequestFailedException::class, 'not Umami');
});

describe('what it refuses to ask', function () {
    it('never sends credentials over plain http', function () {
        Http::fake();

        expect(fn () => $this->client->websites(new UmamiCredentials('http://umami.example.com/api', null, 'k')))->toThrow(UmamiRequestFailedException::class, 'not https');
        Http::assertNothingSent();
    });

    it('does not reach a private address', function () {
        Http::fake();

        expect(fn () => $this->client->websites(new UmamiCredentials('https://10.0.0.5/api', null, 'k')))->toThrow(UmamiRequestFailedException::class);
        Http::assertNothingSent();
    });
});

describe('reading a website', function () {
    beforeEach(function () {
        $this->key = new UmamiCredentials('https://umami.example.com/api', null, 'k');
    });

    it('reads the totals with the period before, in the shape of each Umami version', function (array $answer) {
        Http::fake(['umami.example.com/api/websites/site-1/stats*' => Http::response($answer)]);

        $stats = $this->client->stats($this->key, 'site-1', now()->subDay(), now(), ['path' => '/pricing', 'unknown' => 'x']);

        expect($stats['current'])->toMatchArray(['visitors' => 10, 'visits' => 12, 'pageviews' => 30])
            ->and($stats['previous'])->toMatchArray(['visitors' => 5]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'compare=prev')
            && str_contains($request->url(), 'path=%2Fpricing')
            && ! str_contains($request->url(), 'unknown'));
    })->with([
        'with a comparison' => [['visitors' => 10, 'visits' => 12, 'pageviews' => 30, 'bounces' => 3, 'totaltime' => 600, 'comparison' => ['visitors' => 5, 'visits' => 6, 'pageviews' => 9, 'bounces' => 1, 'totaltime' => 100]]],
        'each figure with its previous value' => [['visitors' => ['value' => 10, 'prev' => 5], 'visits' => ['value' => 12, 'prev' => 6], 'pageviews' => ['value' => 30, 'prev' => 9], 'bounces' => ['value' => 3, 'prev' => 1], 'totaltime' => ['value' => 600, 'prev' => 100]]],
    ]);

    it('reads the notes, skipping any without a date', function () {
        Http::fake(['umami.example.com/api/websites/site-1/annotations*' => Http::response(['data' => [
            ['id' => 'n1', 'date' => '2026-11-27T00:00:00Z', 'allDay' => true, 'note' => 'Black Friday'],
            ['id' => 'n2', 'date' => '2026-11-27T08:30:00Z', 'allDay' => false, 'note' => 'Mail sent'],
            ['id' => 'n3', 'note' => 'broken'],
        ]])]);

        expect($this->client->annotations($this->key, 'site-1'))->toBe([
            ['id' => 'n1', 'at' => '2026-11-27T00:00:00Z', 'all_day' => true, 'note' => 'Black Friday'],
            ['id' => 'n2', 'at' => '2026-11-27T08:30:00Z', 'all_day' => false, 'note' => 'Mail sent'],
        ]);
    });

    it('refuses a breakdown Umami does not have', function () {
        Http::fake();

        $this->client->metrics($this->key, 'site-1', 'password', now()->subDay(), now());
    })->throws(UmamiRequestFailedException::class);
});
