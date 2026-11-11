<?php

declare(strict_types=1);

use App\Domains\Connectors\MobilePay\Data\MobilePayCredentials;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use App\Domains\Connectors\MobilePay\Support\MobilePayClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Connectors\FakesMobilePay;

covers(MobilePayClient::class);

uses(FakesMobilePay::class);

beforeEach(function () {
    $this->travelTo('2026-11-10 12:00:00');
    $this->fakeMobilePay();
    $this->client = app(MobilePayClient::class);
    $this->keys = new MobilePayCredentials('client-id', 'client-secret', 'subscription-key', '123455');
    $this->tokenRequests = fn (): int => Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/accesstoken/get'))->count();
});

describe('the access token', function () {
    it('is asked for with the sales unit\'s keys, and sent with them on every call', function () {
        $this->client->ledger($this->keys);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.vipps.no/accesstoken/get'
            && $request->header('client_id') === ['client-id'] && $request->header('client_secret') === ['client-secret']
            && $request->header('Ocp-Apim-Subscription-Key') === ['subscription-key'] && $request->header('Merchant-Serial-Number') === ['123455']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.vipps.no/settlement/v1/ledgers')
            && $request->header('Authorization') === ['Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1Ni']
            && $request->header('Ocp-Apim-Subscription-Key') === ['subscription-key'] && $request->header('Merchant-Serial-Number') === ['123455']);
    });

    it('is kept until five minutes before it expires', function () {
        $this->client->ledger($this->keys);
        $this->client->ledger($this->keys);
        $this->travel(86398 - 300 - 10)->seconds();
        $this->client->ledger($this->keys);

        expect(($this->tokenRequests)())->toBe(1);

        $this->travel(20)->seconds();
        $this->client->ledger($this->keys);

        expect(($this->tokenRequests)())->toBe(2);
    });

    it('is asked for again once a call refuses it', function () {
        $this->mobilePayAnswers['/settlement/v1/ledgers'] = [['title' => 'Unauthorized'], 401];

        expect(fn () => $this->client->ledger($this->keys))->toThrow(MobilePayRequestFailedException::class, __('connectors.mobilepay.errors.unauthorized'));

        $this->mobilePayAnswers = [];
        $this->client->ledger($this->keys);

        expect(($this->tokenRequests)())->toBe(2);
    });

    it('says the keys were refused', function () {
        $this->mobilePayAnswers['/accesstoken/get'] = [['error' => 'unauthorized_client'], 401];

        $this->client->ledger($this->keys);
    })->throws(MobilePayRequestFailedException::class, 'refused the API keys');
});

describe('finding the ledger', function () {
    it('takes the one that settles for the merchant serial number, on any page', function () {
        $this->mobilePayAnswers['/settlement/v1/ledgers'] = [['items' => [FakesMobilePay::ledger('1', 'DKK', '999999', 'Other')], 'cursor' => 'next']];
        $this->mobilePayAnswers['/settlement/v1/ledgers?cursor=next'] = [['items' => [FakesMobilePay::ledger('302321', 'NOK', '123455', 'ACME Fitness Oslo')], 'cursor' => '']];

        expect($this->client->ledger($this->keys))->toBe(['ledger_id' => '302321', 'currency' => 'NOK', 'name' => 'ACME Fitness Oslo']);
    });

    it('says when no ledger settles for it', function () {
        $this->client->ledger(new MobilePayCredentials('client-id', 'client-secret', 'subscription-key', '555555'));
    })->throws(MobilePayRequestFailedException::class, 'no ledger for merchant serial number 555555');
});

describe('a ledger date', function () {
    it('counts captures as revenue and transactions, refunds, capture fees and payouts', function () {
        expect($this->client->day($this->keys, '302321', '2026-11-09'))->toBe(['revenue' => 59900, 'transactions' => 2, 'refunds' => 10000, 'fees' => 1500, 'payouts' => 48400]);
    });

    it('reads every page of an open date', function () {
        expect($this->client->day($this->keys, '302321', '2026-11-10'))->toBe(['revenue' => 25000, 'transactions' => 2, 'refunds' => 0, 'fees' => 0, 'payouts' => 0]);
    });

    it('keeps a closed date, and asks for an open one again after ten minutes', function () {
        $reports = fn (string $date): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/dates/'.$date))->count();
        $this->client->days($this->keys, '302321', CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-10'));
        $this->travel(11)->minutes();
        $this->client->days($this->keys, '302321', CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-10'));

        expect($reports('2026-11-09'))->toBe(2)
            ->and($reports('2026-11-10'))->toBe(6);
    });
});
