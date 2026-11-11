<?php

declare(strict_types=1);

namespace Tests\Support\Connectors;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stands Vipps MobilePay's Access Token and Report APIs in for sales unit 123455, which
 * settles to ledger 302321 in NOK. The shapes are the documented examples:
 *
 * - token: developer.vippsmobilepay.com/docs/APIs/access-token-api/standard-authentication
 * - ledgers: developer.vippsmobilepay.com/docs/APIs/report-api/api-guide/fetching-report-data
 * - entries and their types: the same page, and .../report-api/api-guide/entry-types
 *
 * On 2026-11-09 two captures, a refund, retained fees and a payout were booked, with two
 * capture fees; today, 2026-11-10, is open and has two captures over two pages; 2026-11-01
 * one capture. Every other date is closed and empty.
 */
trait FakesMobilePay
{
    /**
     * Paths answered otherwise, as a body and a status: refused keys, another ledger. A path
     * with its query is matched before the path alone.
     *
     * @var array<string, array{0: mixed, 1?: int}>
     */
    public array $mobilePayAnswers = [];

    public function fakeMobilePay(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $answer = $this->mobilePayAnswers[$path.($query === [] ? '' : '?'.http_build_query($query))] ?? $this->mobilePayAnswers[$path] ?? null;

            if ($answer !== null) {
                return Http::response(...$answer);
            }

            if ($path === '/accesstoken/get') {
                return Http::response(['token_type' => 'Bearer', 'expires_in' => '86398', 'expires_on' => '1495271273', 'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1Ni']);
            }

            if ($path === '/settlement/v1/ledgers') {
                return Http::response(['items' => [self::ledger('302321', 'NOK', '123455', 'ACME Fitness Oslo')], 'cursor' => '']);
            }

            if (preg_match('~^/report/v2/ledgers/302321/(funds|fees)/dates/(\d{4}-\d{2}-\d{2})$~', $path, $match) === 1) {
                return Http::response(self::entries($match[1], $match[2], is_string($query['cursor'] ?? null) ? $query['cursor'] : null));
            }

            return Http::response(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], 404);
        });
    }

    /**
     * A ledger as GET /settlement/v1/ledgers lists it.
     *
     * @return array<string, mixed>
     */
    public static function ledger(string $id, string $currency, string $msn, string $name): array
    {
        return [
            'ledgerId' => $id,
            'currency' => $currency,
            'payoutBankAccount' => ['scheme' => 'BBAN:NO', 'id' => '86011117947'],
            'owner' => ['scheme' => 'business:NO:ORG', 'id' => '987654321'],
            'settlesForRecipientHandles' => ['api:'.$msn],
            'salesUnits' => [['name' => $name, 'recipientHandle' => 'api:'.$msn, 'businessIdentifier' => ['scheme' => 'business:NO:ORG', 'id' => '987654321']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function entries(string $topic, string $date, ?string $cursor): array
    {
        $entry = fn (string $type, int $amount, string $psp = '3343121302'): array => [
            'pspReference' => $psp, 'time' => $date.'T10:00:00.000000Z', 'ledgerDate' => $date, 'entryType' => $type,
            'reference' => 'acme-shop-123-order123abc', 'currency' => 'NOK', 'amount' => $amount,
            'balanceBefore' => 0, 'balanceAfter' => 0, 'recipientHandle' => 'NO:123455',
        ];

        return match ([$topic, $date, $cursor]) {
            ['funds', '2026-11-09', null] => ['hasMore' => false, 'tryLater' => false, 'items' => [
                $entry('capture', 49900),
                $entry('capture', 10000, '22342342342'),
                $entry('refund', -10000, '22342342342'),
                $entry('fees-retained', -1500, '302321-fees'),
                $entry('payout-scheduled', -48400, '302321-2000101'),
            ]],
            ['fees', '2026-11-09', null] => ['hasMore' => false, 'tryLater' => false, 'items' => [
                $entry('capture-fee', -1250),
                $entry('capture-fee', -250, '22342342342'),
                $entry('fees-retained', 1500, '302321-fees'),
            ]],
            ['funds', '2026-11-10', null] => ['cursor' => 'eyJhZnRlckxlZGdlcklkIjoie', 'hasMore' => true, 'tryLater' => true, 'items' => [$entry('capture', 20000)]],
            ['funds', '2026-11-10', 'eyJhZnRlckxlZGdlcklkIjoie'] => ['hasMore' => false, 'tryLater' => true, 'items' => [$entry('capture', 5000)]],
            ['funds', '2026-11-01', null] => ['hasMore' => false, 'tryLater' => false, 'items' => [$entry('capture', 30000)]],
            // Today stays open until the ledger's cutoff.
            default => ['hasMore' => false, 'tryLater' => $date === '2026-11-10', 'items' => []],
        };
    }
}
