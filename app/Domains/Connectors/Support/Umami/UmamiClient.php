<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Support\Umami;

use App\Domains\Connectors\Data\Umami\UmamiCredentials;
use App\Domains\Connectors\Exceptions\Umami\UmamiRequestFailedException;
use App\Domains\Health\Exceptions\HealthExceptionInterface;
use App\Domains\Health\Support\NetworkGuard;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Umami's API, as its documentation describes it (docs.umami.is/docs/api). Every call is
 * signed with a bearer token: an API key as it is, or for a self-hosted login the token
 * POST /auth/login gives, kept for an hour and asked again once when Umami refuses it.
 *
 * Calls go through the network guard like a data source's, https only, redirects not
 * followed and the answer capped, as the address is whatever the user typed.
 */
class UmamiClient
{
    public const int TIMEOUT_S = 15;

    public const int MAX_BODY_BYTES = 4_194_304;

    private const int TOKEN_SECONDS = 3600;

    /** The breakdowns /metrics answers, by the name Umami gives them. */
    public const array METRIC_TYPES = [
        'path', 'entry', 'exit', 'title', 'query', 'hostname', 'referrer', 'domain', 'channel', 'event', 'tag',
        'browser', 'os', 'device', 'screen', 'language', 'country', 'region', 'city',
        'utmSource', 'utmMedium', 'utmCampaign', 'utmContent', 'utmTerm',
    ];

    /** The filters every report takes, each matching one value. */
    public const array FILTERS = [
        'path', 'referrer', 'title', 'query', 'os', 'browser', 'device', 'country', 'region', 'city', 'tag',
        'hostname', 'language', 'event', 'utmSource', 'utmMedium', 'utmCampaign', 'utmContent', 'utmTerm',
    ];

    public function __construct(
        private readonly NetworkGuard $guard,
    ) {
    }

    /**
     * The websites the credentials can read: always the user's own and those of each of
     * their teams, named by the team, and for an administrator with neither, every website
     * on the instance, which these leave out when another user made them.
     *
     * @return list<array{id: string, name: string, domain: string|null, team: string|null}>
     *
     * @throws UmamiRequestFailedException
     */
    public function websites(UmamiCredentials $credentials): array
    {
        $found = [];

        foreach (self::rows($this->optional($credentials, '/me/teams', [])) as $team) {
            if (is_string($team['id'] ?? null)) {
                $name = is_string($team['name'] ?? null) ? $team['name'] : $team['id'];
                $found = [...$found, ...self::websiteRows($this->optional($credentials, '/teams/'.rawurlencode($team['id']).'/websites', ['pageSize' => 200]), $name)];
            }
        }

        // After the teams', so a website both lists is filed under its team.
        $found = [...$found, ...self::websiteRows($this->get($credentials, '/websites', ['pageSize' => 200]))];

        if ($found === []) {
            $found = self::websiteRows($this->optional($credentials, '/admin/websites', ['pageSize' => 200]));
        }

        return array_values(collect($found)->unique('id')->all());
    }

    /**
     * One website by its id, if the credentials can read it.
     *
     * @return array{id: string, name: string, domain: string|null, team: string|null}
     *
     * @throws UmamiRequestFailedException when it does not exist or cannot be read
     */
    public function website(UmamiCredentials $credentials, string $websiteId): array
    {
        $rows = self::websiteRows([$this->get($credentials, '/websites/'.rawurlencode($websiteId), [])]);

        return $rows[0] ?? throw new UmamiRequestFailedException(__('connectors.umami.errors.unknown_website'));
    }

    /**
     * A listing the credentials may not be allowed to read, such as the administrator's.
     *
     * @param  array<string, int|string>  $query
     * @return array<mixed>
     */
    private function optional(UmamiCredentials $credentials, string $path, array $query): array
    {
        try {
            return $this->get($credentials, $path, $query);
        } catch (UmamiRequestFailedException) {
            return [];
        }
    }

    /**
     * A listing's rows: a page of them under data, or older versions' bare list.
     *
     * @param  array<mixed>  $answer
     * @return list<array<mixed>>
     */
    private static function rows(array $answer): array
    {
        $rows = array_is_list($answer) ? $answer : ($answer['data'] ?? null);

        if (! is_array($rows)) {
            throw new UmamiRequestFailedException(__('connectors.umami.errors.not_umami'));
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param  array<mixed>  $answer
     * @return list<array{id: string, name: string, domain: string|null, team: string|null}>
     */
    private static function websiteRows(array $answer, ?string $team = null): array
    {
        return array_values(array_filter(array_map(fn (array $row): ?array => is_string($row['id'] ?? null) ? [
            'id' => $row['id'],
            'name' => is_string($row['name'] ?? null) ? $row['name'] : $row['id'],
            'domain' => is_string($row['domain'] ?? null) ? $row['domain'] : null,
            'team' => $team,
        ] : null, $answer === [] ? [] : self::rows($answer))));
    }

    /**
     * Totals for the range, with the range before it of the same length.
     *
     * @param  array<string, string>  $filters
     * @return array{current: array<string, int|float>, previous: array<string, int|float>}
     */
    public function stats(UmamiCredentials $credentials, string $websiteId, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $answer = $this->get($credentials, '/websites/'.rawurlencode($websiteId).'/stats', [...$this->range($from, $to), 'compare' => 'prev', ...$this->filters($filters)]);
        $totals = fn (array $values): array => array_map(
            // Older versions answer each figure as {value, prev}.
            fn (mixed $v): int|float => is_array($v) ? self::number($v['value'] ?? 0) : self::number($v),
            array_intersect_key($values, array_flip(['pageviews', 'visitors', 'visits', 'bounces', 'totaltime'])),
        );
        $previous = is_array($answer['comparison'] ?? null)
            ? $totals($answer['comparison'])
            : array_map(fn (mixed $v): int|float => is_array($v) ? self::number($v['prev'] ?? 0) : 0, array_intersect_key($answer, array_flip(['pageviews', 'visitors', 'visits', 'bounces', 'totaltime'])));

        return ['current' => $totals($answer), 'previous' => $previous];
    }

    /** Visitors seen in the last few minutes. */
    public function active(UmamiCredentials $credentials, string $websiteId): int
    {
        $answer = $this->get($credentials, '/websites/'.rawurlencode($websiteId).'/active', []);

        return (int) self::number($answer['visitors'] ?? ($answer['x'] ?? 0));
    }

    /**
     * Page views and sessions per unit, each point's time a local time in $timezone.
     *
     * @param  'minute'|'hour'|'day'|'month'  $unit
     * @param  array<string, string>  $filters
     * @return array{pageviews: list<array{x: string, y: int|float}>, sessions: list<array{x: string, y: int|float}>}
     */
    public function pageviews(UmamiCredentials $credentials, string $websiteId, CarbonImmutable $from, CarbonImmutable $to, string $unit, string $timezone, array $filters = []): array
    {
        $answer = $this->get($credentials, '/websites/'.rawurlencode($websiteId).'/pageviews', [...$this->range($from, $to), 'unit' => $unit, 'timezone' => $timezone, ...$this->filters($filters)]);

        return ['pageviews' => self::points($answer['pageviews'] ?? []), 'sessions' => self::points($answer['sessions'] ?? [])];
    }

    /**
     * The values of one breakdown, most seen first, with page views, visitors, visits, bounces and time for each.
     *
     * @param  array<string, string>  $filters
     * @return list<array{name: string, pageviews: int|float, visitors: int|float, visits: int|float, bounces: int|float, totaltime: int|float}>
     */
    public function expandedMetrics(UmamiCredentials $credentials, string $websiteId, string $type, CarbonImmutable $from, CarbonImmutable $to, int $limit = 10, array $filters = []): array
    {
        $answer = $this->get($credentials, '/websites/'.rawurlencode($websiteId).'/metrics/expanded', [...$this->range($from, $to), 'type' => $this->type($type), 'limit' => $limit, ...$this->filters($filters)]);

        return array_values(array_map(fn (array $row): array => [
            'name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
            'pageviews' => self::number($row['pageviews'] ?? 0),
            'visitors' => self::number($row['visitors'] ?? 0),
            'visits' => self::number($row['visits'] ?? 0),
            'bounces' => self::number($row['bounces'] ?? 0),
            'totaltime' => self::number($row['totaltime'] ?? 0),
        ], array_filter(array_is_list($answer) ? $answer : [], is_array(...))));
    }

    /**
     * One custom event's count per unit, each point's time a local time in $timezone.
     *
     * @param  'minute'|'hour'|'day'|'month'  $unit
     * @return list<array{x: string, y: int|float}>
     */
    public function eventSeries(UmamiCredentials $credentials, string $websiteId, string $event, CarbonImmutable $from, CarbonImmutable $to, string $unit, string $timezone): array
    {
        $answer = $this->get($credentials, '/websites/'.rawurlencode($websiteId).'/events/series', [...$this->range($from, $to), 'unit' => $unit, 'timezone' => $timezone, 'event' => $event]);

        // Each row is {x: event name, t: time, y: count}, for every event the filter let through.
        return array_values(array_map(
            fn (array $row): array => ['x' => (string) $row['t'], 'y' => self::number($row['y'] ?? 0)],
            array_filter(array_is_list($answer) ? $answer : [], fn (mixed $row): bool => is_array($row) && ($row['x'] ?? null) === $event && is_scalar($row['t'] ?? null)),
        ));
    }

    /**
     * The values of one breakdown with their count, most seen first.
     *
     * @param  array<string, string>  $filters
     * @return list<array{x: string, y: int|float}>
     */
    public function metrics(UmamiCredentials $credentials, string $websiteId, string $type, CarbonImmutable $from, CarbonImmutable $to, int $limit = 10, array $filters = []): array
    {
        $answer = $this->get($credentials, '/websites/'.rawurlencode($websiteId).'/metrics', [...$this->range($from, $to), 'type' => $this->type($type), 'limit' => $limit, ...$this->filters($filters)]);

        return self::points(array_is_list($answer) ? $answer : []);
    }

    /**
     * The website's annotations: notes Umami shows on its charts, such as a campaign's
     * launch, newest first. An all-day one is dated midnight UTC of its day.
     *
     * @return list<array{id: string, at: string, all_day: bool, note: string}>
     *
     * @throws UmamiRequestFailedException
     */
    public function annotations(UmamiCredentials $credentials, string $websiteId): array
    {
        $rows = self::rows($this->get($credentials, '/websites/'.rawurlencode($websiteId).'/annotations', ['pageSize' => 200]));

        return array_values(array_filter(array_map(fn (array $row): ?array => is_string($row['id'] ?? null) && is_string($row['date'] ?? null) ? [
            'id' => $row['id'],
            'at' => $row['date'],
            'all_day' => ($row['allDay'] ?? true) !== false,
            'note' => is_string($row['note'] ?? null) ? $row['note'] : '',
        ] : null, $rows)));
    }

    /**
     * Writes an annotation. A whole day is sent as its date alone, which Umami keeps as
     * midnight UTC; a moment with its time.
     *
     * @throws UmamiRequestFailedException
     */
    public function createAnnotation(UmamiCredentials $credentials, string $websiteId, CarbonImmutable $at, bool $allDay, string $note): void
    {
        $this->write($credentials, 'POST', '/websites/'.rawurlencode($websiteId).'/annotations', [
            'date' => $allDay ? $at->format('Y-m-d') : $at->utc()->format('Y-m-d\TH:i:s\Z'),
            'allDay' => $allDay,
            'note' => $note,
        ]);
    }

    /**
     * @throws UmamiRequestFailedException
     */
    public function deleteAnnotation(UmamiCredentials $credentials, string $websiteId, string $annotationId): void
    {
        $this->write($credentials, 'DELETE', '/websites/'.rawurlencode($websiteId).'/annotations/'.rawurlencode($annotationId), null);
    }

    /**
     * One authorized GET, signing in again once if Umami no longer takes a cached login.
     *
     * @param  array<string, int|string>  $query
     * @return array<mixed>
     *
     * @throws UmamiRequestFailedException
     */
    public function get(UmamiCredentials $credentials, string $path, array $query): array
    {
        $url = $credentials->baseUrl.$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return $this->json($this->authorized($credentials, fn (string $token): Response => $this->send($this->request()->withToken($token), 'GET', $url)));
    }

    /**
     * One authorized write. Never retried on a dropped connection, as Umami has no key to
     * tell a repeat from a second note.
     *
     * @param  array<string, mixed>|null  $body
     *
     * @throws UmamiRequestFailedException
     */
    private function write(UmamiCredentials $credentials, string $method, string $path, ?array $body): void
    {
        $pending = fn (string $token): PendingRequest => Http::withoutRedirecting()->timeout(self::TIMEOUT_S)->connectTimeout(5)->acceptJson()
            ->withUserAgent('Lumberjack-Umami/1')->withToken($token);

        $this->json($this->authorized($credentials, fn (string $token): Response => $this->send(
            $body === null ? $pending($token) : $pending($token)->withBody((string) json_encode($body), 'application/json'),
            $method,
            $credentials->baseUrl.$path,
        )));
    }

    /**
     * Sends with the bearer token, and once more with a fresh login if Umami no longer
     * takes the cached one.
     *
     * @param  Closure(string): Response  $send
     *
     * @throws UmamiRequestFailedException
     */
    private function authorized(UmamiCredentials $credentials, Closure $send): Response
    {
        $response = $send($this->token($credentials));

        if ($response->status() === 401 && $credentials->usesLogin()) {
            Cache::forget($this->tokenKey($credentials));
            $response = $send($this->token($credentials));
        }

        return $response;
    }

    /**
     * The bearer token: the API key, or a login's token.
     *
     * @throws UmamiRequestFailedException
     */
    private function token(UmamiCredentials $credentials): string
    {
        if (! $credentials->usesLogin()) {
            return $credentials->secret;
        }

        $cached = Cache::get($this->tokenKey($credentials));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // A login is not retried: one refused password is enough.
        $answer = $this->json($this->send(
            Http::withoutRedirecting()->timeout(self::TIMEOUT_S)->connectTimeout(5)->acceptJson()->asJson()
                ->withUserAgent('Lumberjack-Umami/1')
                ->withBody((string) json_encode(['username' => $credentials->username, 'password' => $credentials->secret]), 'application/json'),
            'POST',
            $credentials->baseUrl.'/auth/login',
        ));

        if (($answer['requiresTwoFactor'] ?? false) === true) {
            throw new UmamiRequestFailedException(__('connectors.umami.errors.two_factor'));
        }

        $token = $answer['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new UmamiRequestFailedException(__('connectors.umami.errors.not_umami'));
        }

        Cache::put($this->tokenKey($credentials), $token, self::TOKEN_SECONDS);

        return $token;
    }

    private function tokenKey(UmamiCredentials $credentials): string
    {
        return 'umami:token:'.$credentials->fingerprint();
    }

    private function request(): PendingRequest
    {
        return Http::retry(
            times: 3,
            sleepMilliseconds: 200,
            when: fn (Throwable $e): bool => $e instanceof ConnectionException,
            throw: false,
        )->withoutRedirecting()->timeout(self::TIMEOUT_S)->connectTimeout(5)->acceptJson()->withUserAgent('Lumberjack-Umami/1');
    }

    /**
     * @throws UmamiRequestFailedException
     */
    private function send(PendingRequest $request, string $method, string $url): Response
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new UmamiRequestFailedException(__('connectors.umami.errors.not_https'));
        }

        try {
            return $this->guard->send($request, $method, $url, self::MAX_BODY_BYTES + 1, (bool) config('health.data_sources_allow_private', false));
        } catch (HealthExceptionInterface|ConnectionException $e) {
            throw new UmamiRequestFailedException(self::clean($e->getMessage()), previous: $e);
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws UmamiRequestFailedException
     */
    private function json(Response $response): array
    {
        $status = $response->status();

        if ($status !== 200) {
            throw new UmamiRequestFailedException(match ($status) {
                401, 403 => __('connectors.umami.errors.unauthorized'),
                404 => __('connectors.umami.errors.not_found'),
                429 => __('connectors.umami.errors.rate_limited'),
                default => __('connectors.umami.errors.status', ['status' => $status]),
            });
        }

        if (strlen($response->body()) > self::MAX_BODY_BYTES) {
            throw new UmamiRequestFailedException(__('connectors.umami.errors.too_large'));
        }

        $data = json_decode($response->body(), true);

        // GET /websites/{id} answers null for a website that does not exist.
        return is_array($data) ? $data : throw new UmamiRequestFailedException(__($data === null && $response->body() === 'null' ? 'connectors.umami.errors.not_found' : 'connectors.umami.errors.not_umami'));
    }

    /**
     * @return array{startAt: int, endAt: int}
     */
    private function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['startAt' => $from->getTimestampMs(), 'endAt' => $to->getTimestampMs()];
    }

    /**
     * The filters Umami knows, each a non-empty string.
     *
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function filters(array $filters): array
    {
        return array_filter(array_intersect_key($filters, array_flip(self::FILTERS)), fn (string $v): bool => $v !== '');
    }

    private function type(string $type): string
    {
        return in_array($type, self::METRIC_TYPES, true) ? $type : throw new UmamiRequestFailedException(__('connectors.umami.errors.unknown_type'));
    }

    /**
     * @return list<array{x: string, y: int|float}>
     */
    private static function points(mixed $rows): array
    {
        return array_values(array_map(fn (array $row): array => [
            'x' => is_scalar($row['x'] ?? null) ? (string) $row['x'] : '',
            'y' => self::number($row['y'] ?? 0),
        ], array_filter(is_array($rows) ? $rows : [], is_array(...))));
    }

    private static function number(mixed $value): int|float
    {
        return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? +$value : 0);
    }

    /** Guzzle names the URL in what it says, which may hold the instance's address. */
    private static function clean(string $message): string
    {
        return mb_substr((string) preg_replace(['~\s*\(see [^)]*\)~', '~\s*(?:for )?\b[a-z][a-z0-9+.-]*://\S+~i'], '', $message), 0, 300);
    }
}
