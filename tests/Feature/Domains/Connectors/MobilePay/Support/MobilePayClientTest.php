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
        expect($this->client->day($this->keys, '302321', '2026-11-09')['figures'])->toBe(['revenue' => 59900, 'transactions' => 2, 'refunds' => 10000, 'fees' => 1500, 'payouts' => 48400]);
    });

    it('keeps what the lists and breakdowns need of its entries', function () {
        $sale = fn (string $time, string $type, int $amount, string $reference): array => ['at' => strtotime('2026-11-09 '.$time.' UTC'), 'type' => $type, 'amount' => $amount, 'reference' => $reference];

        expect($this->client->day($this->keys, '302321', '2026-11-09'))->toMatchArray([
            'balance' => ['at' => strtotime('2026-11-09 23:59:30 UTC'), 'amount' => 0],
            'hours' => [strtotime('2026-11-09 09:00 UTC') => ['sales' => 1, 'revenue' => 49900], strtotime('2026-11-09 18:00 UTC') => ['sales' => 1, 'revenue' => 10000]],
            'units' => ['NO:123455' => 49900, 'NO:123456' => 10000],
            'latest' => [$sale('19:00', 'refund', -10000, 'order-b'), $sale('18:30', 'capture', 10000, 'order-b'), $sale('09:15', 'capture', 49900, 'acme-shop-123-order123abc')],
            'largest' => [$sale('09:15', 'capture', 49900, 'acme-shop-123-order123abc'), $sale('18:30', 'capture', 10000, 'order-b')],
            'payouts' => [['at' => strtotime('2026-11-09 23:59:30 UTC'), 'date' => '2026-11-09', 'amount' => 48400]],
        ]);
    });

    it('reads every page of an open date', function () {
        expect($this->client->day($this->keys, '302321', '2026-11-10')['figures'])->toBe(['revenue' => 25000, 'transactions' => 2, 'refunds' => 0, 'fees' => 0, 'payouts' => 0]);
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

describe('a range of dates', function () {
    beforeEach(function () {
        $this->reports = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/report/v2/'))->count();
    });

    it('asks for each date not kept once per topic, and an open date\'s next page after', function () {
        $days = $this->client->days($this->keys, '302321', CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-10'));

        expect(array_keys($days))->toBe(['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10'])
            ->and(($this->reports)())->toBe(7 * 2 + 1)
            ->and(($this->tokenRequests)())->toBe(1)
            ->and($days['2026-11-10']['figures']['revenue'])->toBe(25000);

        $this->client->days($this->keys, '302321', CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-10'));

        expect(($this->reports)())->toBe(15);
    });

    it('says why a date failed, keeping the dates read with it', function () {
        $this->mobilePayAnswers['/report/v2/ledgers/302321/fees/dates/2026-11-08'] = [['title' => 'Internal Server Error'], 500];

        expect(fn () => $this->client->days($this->keys, '302321', CarbonImmutable::parse('2026-11-07'), CarbonImmutable::parse('2026-11-09')))
            ->toThrow(MobilePayRequestFailedException::class, __('connectors.mobilepay.errors.status', ['status' => 500]));

        $this->mobilePayAnswers = [];
        $days = $this->client->days($this->keys, '302321', CarbonImmutable::parse('2026-11-07'), CarbonImmutable::parse('2026-11-09'));

        expect($days['2026-11-09']['figures']['revenue'])->toBe(59900)
            ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/dates/2026-11-08'))->count())->toBe(4)
            ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/dates/2026-11-09'))->count())->toBe(2);
    });

    it('asks again for a date whose connection failed in the pool', function () {
        $this->mobilePayDropped = ['/report/v2/ledgers/302321/funds/dates/2026-11-09'];

        $days = $this->client->days($this->keys, '302321', CarbonImmutable::parse('2026-11-08'), CarbonImmutable::parse('2026-11-09'));

        expect($days['2026-11-09']['figures']['revenue'])->toBe(59900)
            ->and($this->mobilePayDropped)->toBe([]);
    });
});
