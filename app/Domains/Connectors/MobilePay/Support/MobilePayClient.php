<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Support;

use App\Domains\Connectors\MobilePay\Data\MobilePayCredentials;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Vipps MobilePay's Report API (developer.vippsmobilepay.com/docs/APIs/report-api), read
 * for one sales unit with its own API keys. It reports booked ledger entries per ledger
 * date, which is open until the day's cutoff: today is incomplete until then. It has no
 * test environment, so it is only ever asked in production.
 *
 * Each call carries an access token from the Access Token API, which lasts 24 hours and is
 * kept until shortly before then. A closed day never changes, so its figures are kept for
 * a month and only the open days are asked again.
 */
class MobilePayClient
{
    public const string API = 'https://api.vipps.no';

    public const int TIMEOUT_S = 15;

    /** How long an open day's figures are kept; Vipps suggests asking an open date again after about 30 minutes. */
    public const int OPEN_DAY_SECONDS = 600;

    public const int CLOSED_DAY_SECONDS = 30 * 86400;

    /** Pages of ledgers, or of one day's entries, read at most. */
    private const int MAX_PAGES = 50;

    /**
     * The ledger the sales unit settles to: the one listing its merchant serial number among
     * the handles it settles for (api:123456 for an online sales unit, NO:, DK: or FI: before
     * a Vipps or MobilePay number), its currency and the sales unit's name.
     *
     * @return array{ledger_id: string, currency: string, name: string}
     *
     * @throws MobilePayRequestFailedException
     */
    public function ledger(MobilePayCredentials $credentials): array
    {
        $cursor = '';

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->get($credentials, '/settlement/v1/ledgers', $cursor === '' ? [] : ['cursor' => $cursor]);

            foreach (self::listOf($data['items'] ?? null) as $ledger) {
                $handle = self::handle($ledger['settlesForRecipientHandles'] ?? null, $credentials->merchantSerialNumber);

                if ($handle !== null && is_scalar($ledger['ledgerId'] ?? null)) {
                    $unit = collect(self::listOf($ledger['salesUnits'] ?? null))->first(fn (array $unit): bool => ($unit['recipientHandle'] ?? null) === $handle);

                    return [
                        'ledger_id' => (string) $ledger['ledgerId'],
                        'currency' => is_string($ledger['currency'] ?? null) ? $ledger['currency'] : '',
                        'name' => is_string($unit['name'] ?? null) && $unit['name'] !== '' ? $unit['name'] : $credentials->merchantSerialNumber,
                    ];
                }
            }

            $cursor = is_string($data['cursor'] ?? null) ? $data['cursor'] : '';

            if ($cursor === '') {
                break;
            }
        }

        throw new MobilePayRequestFailedException(__('connectors.mobilepay.errors.no_ledger', ['msn' => $credentials->merchantSerialNumber]));
    }

    /**
     * Each ledger date from $from to $to, both included, by its date.
     *
     * @return array<string, array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}>
     *
     * @throws MobilePayRequestFailedException
     */
    public function days(MobilePayCredentials $credentials, string $ledgerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];

        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = $this->day($credentials, $ledgerId, $day->format('Y-m-d'));
        }

        return $days;
    }

    /**
     * One ledger date's figures, amounts in minor units: what was captured and how many
     * captures, what was refunded, the fees charged and what was scheduled to be paid out.
     *
     * @return array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}
     *
     * @throws MobilePayRequestFailedException
     */
    public function day(MobilePayCredentials $credentials, string $ledgerId, string $date): array
    {
        $key = 'mobilepay:day:'.$ledgerId.':'.$date;
        $kept = Cache::get($key);

        if (is_array($kept) && is_array($kept['figures'] ?? null)) {
            return self::totals([$kept['figures']]);
        }

        $figures = ['revenue' => 0, 'transactions' => 0, 'refunds' => 0, 'fees' => 0, 'payouts' => 0];
        [$funds, $fundsOpen] = $this->entries($credentials, $ledgerId, 'funds', $date);
        [$fees, $feesOpen] = $this->entries($credentials, $ledgerId, 'fees', $date);

        // Amounts are signed by how they change the balance: a refund or a payout is negative.
        foreach ($funds as $entry) {
            $amount = is_int($entry['amount'] ?? null) ? $entry['amount'] : 0;

            $type = $entry['entryType'] ?? null;

            if ($type === 'capture') {
                $figures['revenue'] += $amount;
                $figures['transactions']++;
            } elseif ($type === 'refund') {
                $figures['refunds'] -= $amount;
            } elseif ($type === 'payout-scheduled' || $type === 'payout-aborted') {
                $figures['payouts'] -= $amount;
            }
        }

        // Fees owed are counted whatever their sign, as the fees ledger is the merchant's debt.
        foreach ($fees as $entry) {
            if (in_array($entry['entryType'] ?? null, ['capture-fee', 'service-fee'], true) && is_int($entry['amount'] ?? null)) {
                $figures['fees'] += abs($entry['amount']);
            }
        }

        Cache::put($key, ['figures' => $figures], $fundsOpen || $feesOpen ? self::OPEN_DAY_SECONDS : self::CLOSED_DAY_SECONDS);

        return $figures;
    }

    /**
     * The sum of several days' figures.
     *
     * @param  iterable<array<mixed>>  $days
     * @return array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}
     */
    public static function totals(iterable $days): array
    {
        $sum = ['revenue' => 0, 'transactions' => 0, 'refunds' => 0, 'fees' => 0, 'payouts' => 0];

        foreach ($days as $figures) {
            foreach ($sum as $key => $value) {
                $sum[$key] = $value + (is_numeric($figures[$key] ?? null) ? (int) $figures[$key] : 0);
            }
        }

        return $sum;
    }

    /**
     * A ledger date's entries in one topic (funds or fees), every page of them, and whether
     * the date is still open (tryLater), so more can come.
     *
     * @return array{list<array<mixed>>, bool}
     *
     * @throws MobilePayRequestFailedException
     */
    private function entries(MobilePayCredentials $credentials, string $ledgerId, string $topic, string $date): array
    {
        $path = '/report/v2/ledgers/'.rawurlencode($ledgerId).'/'.$topic.'/dates/'.$date;
        $entries = [];
        $cursor = '';

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->get($credentials, $path, $cursor === '' ? [] : ['cursor' => $cursor]);
            $entries = [...$entries, ...self::listOf($data['items'] ?? null)];
            $cursor = is_string($data['cursor'] ?? null) ? $data['cursor'] : '';

            if (($data['hasMore'] ?? false) !== true || $cursor === '') {
                return [$entries, ($data['tryLater'] ?? false) === true];
            }
        }

        // ponytail: 50 pages of 1000 entries a day; past that the day reads short and is asked again soon.
        return [$entries, true];
    }

    /**
     * An access token for the sales unit, kept until five minutes before it expires.
     *
     * @throws MobilePayRequestFailedException
     */
    private function token(MobilePayCredentials $credentials): string
    {
        $key = self::tokenKey($credentials);
        $kept = Cache::get($key);

        if (is_string($kept) && $kept !== '') {
            return $kept;
        }

        try {
            $response = $this->http($credentials)
                ->withHeaders(['client_id' => $credentials->clientId, 'client_secret' => $credentials->clientSecret])
                ->withBody('', 'application/json')
                ->post('/accesstoken/get');
        } catch (ConnectionException $e) {
            throw new MobilePayRequestFailedException(__('connectors.mobilepay.errors.unreachable'), previous: $e);
        }

        $data = $response->json();
        $token = is_array($data) && is_string($data['access_token'] ?? null) ? $data['access_token'] : '';

        if ($response->status() !== 200 || $token === '') {
            throw self::failure($response);
        }

        // expires_in comes as a string of seconds, 86398 in production.
        $expiresIn = is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 3600;
        Cache::put($key, $token, max(60, $expiresIn - 300));

        return $token;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<mixed>
     *
     * @throws MobilePayRequestFailedException
     */
    private function get(MobilePayCredentials $credentials, string $path, array $query = []): array
    {
        $token = $this->token($credentials);

        try {
            $response = $this->http($credentials)->withToken($token)->get($path, $query);
        } catch (ConnectionException $e) {
            throw new MobilePayRequestFailedException(__('connectors.mobilepay.errors.unreachable'), previous: $e);
        }

        $data = $response->json();

        if ($response->status() === 200 && is_array($data)) {
            return $data;
        }

        // A token revoked before it expired is asked for again next time.
        if ($response->status() === 401) {
            Cache::forget(self::tokenKey($credentials));
        }

        throw self::failure($response);
    }

    private static function failure(Response $response): MobilePayRequestFailedException
    {
        return new MobilePayRequestFailedException(match ($response->status()) {
            400, 401 => __('connectors.mobilepay.errors.unauthorized'),
            403 => __('connectors.mobilepay.errors.forbidden'),
            404 => __('connectors.mobilepay.errors.not_found'),
            429 => __('connectors.mobilepay.errors.rate_limited'),
            default => __('connectors.mobilepay.errors.status', ['status' => $response->status()]),
        });
    }

    private function http(MobilePayCredentials $credentials): PendingRequest
    {
        return Http::retry(
            times: 3,
            sleepMilliseconds: 200,
            when: fn (?Throwable $e): bool => $e instanceof ConnectionException,
            throw: false,
        )->baseUrl(self::API)->acceptJson()
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $credentials->subscriptionKey,
                'Merchant-Serial-Number' => $credentials->merchantSerialNumber,
                'Vipps-System-Name' => 'lumberjack',
            ])
            ->timeout(self::TIMEOUT_S)->connectTimeout(5);
    }

    /** Per sales unit and keys, so new keys never reuse a token of the old ones. */
    private static function tokenKey(MobilePayCredentials $credentials): string
    {
        return 'mobilepay:token:'.hash('sha256', implode("\0", [$credentials->clientId, $credentials->clientSecret, $credentials->subscriptionKey, $credentials->merchantSerialNumber]));
    }

    /**
     * The handle of a ledger that settles for the merchant serial number, whatever its prefix.
     *
     */
    private static function handle(mixed $handles, string $msn): ?string
    {
        foreach (is_array($handles) ? $handles : [] as $handle) {
            if (is_string($handle) && $msn !== '' && substr($handle, (int) strrpos($handle, ':') + 1) === $msn) {
                return $handle;
            }
        }

        return null;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function listOf(mixed $data): array
    {
        return is_array($data) ? array_values(array_filter($data, is_array(...))) : [];
    }
}
