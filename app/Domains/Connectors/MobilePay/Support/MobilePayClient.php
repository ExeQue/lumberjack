<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Support;

use App\Domains\Connectors\MobilePay\Data\MobilePayCredentials;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
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
 * kept until shortly before then. A closed day never changes, so what is kept of it is
 * kept for a month and only the open days are asked again.
 *
 * @phpstan-type Figures array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}
 * @phpstan-type Entry array{at: int, type: string, amount: int, reference: string}
 * @phpstan-type Payout array{at: int, date: string, amount: int}
 * @phpstan-type Day array{figures: Figures, balance: array{at: int, amount: int}|null, hours: array<int, array{sales: int, revenue: int}>, units: array<string, int>, latest: array<int, Entry>, largest: array<int, Entry>, payouts: array<int, Payout>}
 */
class MobilePayClient
{
    public const string API = 'https://api.vipps.no';

    public const int TIMEOUT_S = 15;

    /** How long an open day's figures are kept; Vipps suggests asking an open date again after about 30 minutes. */
    public const int OPEN_DAY_SECONDS = 600;

    public const int CLOSED_DAY_SECONDS = 30 * 86400;

    /** The most rows a list of entries on the page loads, and so the most of them a day keeps. */
    public const int MAX_LIST = 200;

    /** Days' first pages asked for at once at most. */
    private const int CONCURRENCY = 10;

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
     * @return array<string, Day>
     *
     * @throws MobilePayRequestFailedException
     */
    public function days(MobilePayCredentials $credentials, string $ledgerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];

        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = self::kept($ledgerId, $day->format('Y-m-d'));
        }

        $missing = collect($days)->filter(fn (?array $day): bool => $day === null)->keys();

        if ($missing->isNotEmpty()) {
            $token = $this->token($credentials);
            // The first page of both topics of every day not kept, a few at a time; the pages after follow one by one.
            $firsts = Http::pool(fn (Pool $pool): Collection => $missing->crossJoin(['funds', 'fees'])->map(
                fn (array $call): mixed => $this->configure($pool->as($call[0].'/'.$call[1]), $credentials)->withToken($token)->get(self::path($ledgerId, $call[1], $call[0])),
            ), self::CONCURRENCY);
            $failure = null;

            // A day that fails leaves the others read and kept, and is said once they are.
            foreach ($missing as $date) {
                try {
                    $days[$date] = $this->fetchDay($credentials, $ledgerId, $date, $firsts[$date.'/funds'] ?? null, $firsts[$date.'/fees'] ?? null);
                } catch (MobilePayRequestFailedException $e) {
                    $failure ??= $e;
                }
            }

            if ($failure !== null) {
                throw $failure;
            }
        }

        /** @var array<string, Day> $days */
        return $days;
    }

    /**
     * One ledger date, amounts in minor units, as kept: its figures (what was captured and
     * how many captures, what was refunded, the fees charged and what was scheduled to be
     * paid out), the balance after its latest entry, its captures per hour (by the hour's
     * start, a Unix time) and per sales unit, its latest sales and refunds, its largest
     * captures and its payouts.
     *
     * @return Day
     *
     * @throws MobilePayRequestFailedException
     */
    public function day(MobilePayCredentials $credentials, string $ledgerId, string $date): array
    {
        return self::kept($ledgerId, $date) ?? $this->fetchDay($credentials, $ledgerId, $date);
    }

    /**
     * @return Day|null
     */
    private static function kept(string $ledgerId, string $date): ?array
    {
        $kept = Cache::get(self::dayKey($ledgerId, $date));

        /** @var Day|null */
        return is_array($kept) && is_array($kept['figures'] ?? null) ? $kept : null;
    }

    private static function dayKey(string $ledgerId, string $date): string
    {
        return 'mobilepay:day:v2:'.$ledgerId.':'.$date;
    }

    /**
     * A day read from Vipps MobilePay and kept, starting from the first pages of its topics
     * when they were already asked for together with other days'.
     *
     * @return Day
     *
     * @throws MobilePayRequestFailedException
     */
    private function fetchDay(MobilePayCredentials $credentials, string $ledgerId, string $date, mixed $funds = null, mixed $fees = null): array
    {
        [$funds, $fundsOpen] = $this->entries($credentials, $ledgerId, 'funds', $date, $funds);
        [$fees, $feesOpen] = $this->entries($credentials, $ledgerId, 'fees', $date, $fees);
        $day = self::summarize($funds, $fees);

        Cache::put(self::dayKey($ledgerId, $date), $day, $fundsOpen || $feesOpen ? self::OPEN_DAY_SECONDS : self::CLOSED_DAY_SECONDS);

        return $day;
    }

    /**
     * A day's entries reduced to what is kept of them. The figures, hours and sales units
     * count every entry; of the entries themselves only enough are kept for the longest list
     * the page loads.
     *
     * @param  list<array<mixed>>  $funds
     * @param  list<array<mixed>>  $fees
     * @return Day
     */
    private static function summarize(array $funds, array $fees): array
    {
        $figures = ['revenue' => 0, 'transactions' => 0, 'refunds' => 0, 'fees' => 0, 'payouts' => 0];
        $balance = null;
        $hours = [];
        $units = [];
        $sales = [];
        $payouts = [];

        // Amounts are signed by how they change the balance: a refund or a payout is negative.
        foreach ($funds as $entry) {
            $amount = is_int($entry['amount'] ?? null) ? $entry['amount'] : 0;
            $type = $entry['entryType'] ?? null;
            $at = is_string($entry['time'] ?? null) ? (int) strtotime($entry['time']) : 0;
            $reference = is_string($entry['reference'] ?? null) ? $entry['reference'] : '';

            if (is_int($entry['balanceAfter'] ?? null) && ($balance === null || $at >= $balance['at'])) {
                $balance = ['at' => $at, 'amount' => $entry['balanceAfter']];
            }

            if ($type === 'capture') {
                $figures['revenue'] += $amount;
                $figures['transactions']++;
                $hour = $at - $at % 3600;
                $hours[$hour] = ['sales' => ($hours[$hour]['sales'] ?? 0) + 1, 'revenue' => ($hours[$hour]['revenue'] ?? 0) + $amount];
                $unit = is_string($entry['recipientHandle'] ?? null) ? $entry['recipientHandle'] : '';
                $units[$unit] = ($units[$unit] ?? 0) + $amount;
                $sales[] = ['at' => $at, 'type' => 'capture', 'amount' => $amount, 'reference' => $reference];
            } elseif ($type === 'refund') {
                $figures['refunds'] -= $amount;
                $sales[] = ['at' => $at, 'type' => 'refund', 'amount' => $amount, 'reference' => $reference];
            } elseif ($type === 'payout-scheduled' || $type === 'payout-aborted') {
                $figures['payouts'] -= $amount;
                $payouts[] = ['at' => $at, 'date' => is_string($entry['ledgerDate'] ?? null) ? $entry['ledgerDate'] : gmdate('Y-m-d', $at), 'amount' => -$amount];
            }
        }

        // Fees owed are counted whatever their sign, as the fees ledger is the merchant's debt.
        foreach ($fees as $entry) {
            if (in_array($entry['entryType'] ?? null, ['capture-fee', 'service-fee'], true) && is_int($entry['amount'] ?? null)) {
                $figures['fees'] += abs($entry['amount']);
            }
        }

        $sales = collect($sales);

        return [
            'figures' => $figures,
            'balance' => $balance,
            'hours' => $hours,
            'units' => $units,
            // ponytail: a day keeps its latest MAX_LIST sales and refunds and its MAX_LIST largest captures, as no list loads more.
            'latest' => $sales->sortByDesc('at')->take(self::MAX_LIST)->values()->all(),
            'largest' => $sales->where('type', 'capture')->sortByDesc('amount')->take(self::MAX_LIST)->values()->all(),
            'payouts' => collect($payouts)->sortByDesc('at')->values()->all(),
        ];
    }

    /**
     * A ledger date's entries in one topic (funds or fees), every page of them, and whether
     * the date is still open (tryLater), so more can come. The first page may have come in a
     * pool: a connection that failed there is asked again, retried like any other call.
     *
     * @return array{list<array<mixed>>, bool}
     *
     * @throws MobilePayRequestFailedException
     */
    private function entries(MobilePayCredentials $credentials, string $ledgerId, string $topic, string $date, mixed $first = null): array
    {
        $path = self::path($ledgerId, $topic, $date);
        $entries = [];
        $cursor = '';

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $page === 0 && $first instanceof Response
                ? $this->answer($credentials, $first)
                : $this->get($credentials, $path, $cursor === '' ? [] : ['cursor' => $cursor]);
            $entries = [...$entries, ...self::listOf($data['items'] ?? null)];
            $cursor = is_string($data['cursor'] ?? null) ? $data['cursor'] : '';

            if (($data['hasMore'] ?? false) !== true || $cursor === '') {
                return [$entries, ($data['tryLater'] ?? false) === true];
            }
        }

        // ponytail: 50 pages of 1000 entries a day; past that the day reads short and is asked again soon.
        return [$entries, true];
    }

    private static function path(string $ledgerId, string $topic, string $date): string
    {
        return '/report/v2/ledgers/'.rawurlencode($ledgerId).'/'.$topic.'/dates/'.$date;
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

        return $this->answer($credentials, $response);
    }

    /**
     * A response's body, or why it has none.
     *
     * @return array<mixed>
     *
     * @throws MobilePayRequestFailedException
     */
    private function answer(MobilePayCredentials $credentials, Response $response): array
    {
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
        return $this->configure(Http::retry(
            times: 3,
            sleepMilliseconds: 200,
            when: fn (?Throwable $e): bool => $e instanceof ConnectionException,
            throw: false,
        ), $credentials);
    }

    /** Where and as whom every call goes, pooled or not. */
    private function configure(PendingRequest $request, MobilePayCredentials $credentials): PendingRequest
    {
        return $request->baseUrl(self::API)->acceptJson()
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
