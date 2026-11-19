<?php

declare(strict_types=1);

namespace App\Domains\Ai\Queries;

use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Support\AiPriceBook;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\LikePattern;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tokens and what they cost, from the hourly totals (per key, key owner and model, priced
 * by the hour) or the daily ones (per actor too, priced at the start of the day).
 * Cost is never stored: it is worked out here, with the prices as they are now, so a
 * price added or corrected later prices the usage before it too.
 *
 * Two queries. The first lists what can be priced differently: each provider, model, key
 * and owner in each stretch of time between two prices' starts.
 * Those are few, however many actors there are; each gets its price from AiPriceBook and
 * its payer here. The second sums the totals with those prices joined in, so cost is
 * summed, sorted and paged in the database and only the rows shown leave it. A price
 * change in the middle of a period prices each side with its own price.
 *
 * Cost per row, per million tokens: input less cache reads and writes (never below 0) at
 * the input price, output at the output price (with a reasoning price, the reasoning
 * share of the output at that price instead), cache reads and writes at their own prices
 * or the input price.
 *
 * @phpstan-type Dimension 'bucket'|'environment'|'model'|'fingerprint'|'owner'|'actor'
 * @phpstan-type Only array{fingerprint?: string|list<string>, owner?: list<array{type: string, id: string}>, actor?: list<array{type: string, id: string}>}
 * @phpstan-type Row array{bucket: int|null, environment: string|null, provider: string|null, model: string|null, fingerprint: string|null, owner_type: string|null, owner_id: string|null, actor_type: string|null, actor_id: string|null, calls: int, errors: int, input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int, reasoning_tokens: int, tokens: int, cost_ours: float, cost_customer: float, cost: float, unpriced_tokens: int, tokens_ours: int, tokens_customer: int}
 */
class AiCostQuery
{
    public const string HOURLY = 'hourly';

    public const string DAILY = 'daily';

    /** Between an owner's or actor's type and id in top()'s $among; Postgres text holds no NUL. */
    public const string SEPARATOR = "\x1F";

    private const array COUNTERS = ['calls', 'errors', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens'];

    /** One row's cost, with its price (p) joined in; 0 without one. */
    private const string COST = <<<'SQL'
        CASE WHEN p.input IS NULL THEN 0 ELSE (
            GREATEST(u.input_tokens - u.cache_read_tokens - u.cache_write_tokens, 0) * p.input
            + (u.output_tokens - CASE WHEN p.reasoning IS NULL THEN 0 ELSE LEAST(u.reasoning_tokens, u.output_tokens) END) * p.output
            + CASE WHEN p.reasoning IS NULL THEN 0 ELSE LEAST(u.reasoning_tokens, u.output_tokens) * p.reasoning END
            + u.cache_read_tokens * COALESCE(p.cache_read, p.input)
            + u.cache_write_tokens * COALESCE(p.cache_write, p.input)
        ) / 1000000 END
        SQL;

    /**
     * Everything matching, summed by the dimensions asked for.
     *
     * @param  self::HOURLY|self::DAILY  $table
     * @param  CarbonImmutable  $from  inclusive: an hour's start for hourly totals, a day's for daily ones
     * @param  CarbonImmutable|null  $until  exclusive; null for up to now
     * @param  list<Dimension>  $by  what to sum by; actor needs the daily totals
     * @param  Only  $only  an actor needs the daily totals
     * @param  int  $bucketSeconds  the size of a bucket, when summing by bucket
     * @return list<Row>
     */
    public function get(Project $project, string $table, CarbonImmutable $from, ?CarbonImmutable $until = null, ?string $environment = null, array $by = [], array $only = [], int $bucketSeconds = 3600, ?AiPriceBook $book = null): array
    {
        return array_values(array_map(
            fn (stdClass $row): array => self::row($row),
            $this->summed($project, $table, $from, $until, $environment, $by, $only, $bucketSeconds, $book ?? AiPriceBook::for($project))->get()->all(),
        ));
    }

    /**
     * The one total of everything matching, all zero when nothing does.
     *
     * @param  self::HOURLY|self::DAILY  $table
     * @param  Only  $only
     * @return Row
     */
    public function total(Project $project, string $table, CarbonImmutable $from, ?CarbonImmutable $until = null, ?string $environment = null, array $only = [], ?AiPriceBook $book = null): array
    {
        return $this->get($project, $table, $from, $until, $environment, [], $only, book: $book)[0];
    }

    /**
     * One page of keys or owners (hourly totals) or actors (daily totals), costliest first, and
     * how many there are. A search matches an owner or actor as "type:id", a key by its
     * name, label, short id, provider or owner.
     *
     * @param  'fingerprint'|'owner'|'actor'  $dimension
     * @param  list<string>|null  $among  only these: fingerprints, or type, SEPARATOR and id
     * @return array{rows: list<Row>, total: int}
     */
    public function top(Project $project, CarbonImmutable $from, ?CarbonImmutable $until, ?string $environment, string $dimension, string $search = '', ?int $limit = null, int $offset = 0, ?array $among = null, ?AiPriceBook $book = null): array
    {
        $table = $dimension === 'actor' ? self::DAILY : self::HOURLY;
        $query = $this->summed($project, $table, $from, $until, $environment, [$dimension], [], 3600, $book ?? AiPriceBook::for($project));
        [$type, $id] = $dimension === 'owner' ? ['u.owner_type', 'u.owner_id'] : ['u.actor_type', 'u.actor_id'];

        if ($among !== null) {
            if ($among === []) {
                return ['rows' => [], 'total' => 0];
            }

            $dimension === 'fingerprint'
                ? $query->whereIn('u.fingerprint', $among)
                : $query->whereRaw("({$type} || chr(31) || {$id}) IN (".implode(',', array_fill(0, count($among), '?')).')', $among);
        }

        if ($search !== '') {
            // * stands for anything, as in every search of keys, owners and actors.
            $like = LikePattern::contains(mb_strtolower($search));

            if ($dimension === 'fingerprint') {
                $query->leftJoin('ai_keys as k', fn (JoinClause $join) => $join->where('k.project_id', $project->id)->on('k.fingerprint', '=', 'u.fingerprint'))
                    ->whereRaw("LOWER(CONCAT_WS(' ', k.name, k.label, LEFT(u.fingerprint, 8), u.provider, k.owner_type || ':' || k.owner_id)) LIKE ?", [$like]);
            } else {
                $query->whereRaw("LOWER({$type} || ':' || {$id}) LIKE ?", [$like]);
            }
        }

        $rows = $query
            ->selectRaw('COUNT(*) OVER () AS total_rows')
            ->orderByDesc('cost')
            ->orderByDesc('tokens')
            ->orderBy($dimension === 'fingerprint' ? 'u.fingerprint' : $type)
            ->when($dimension !== 'fingerprint')->orderBy($id)
            ->when($limit !== null)->limit((int) $limit)
            ->offset($offset)
            ->get();

        return [
            'rows' => array_values(array_map(fn (stdClass $row): array => self::row($row), $rows->all())),
            'total' => (int) ($rows->first()->total_rows ?? 0),
        ];
    }

    /**
     * @param  self::HOURLY|self::DAILY  $table
     * @param  list<Dimension>  $by
     * @param  Only  $only
     */
    private function summed(Project $project, string $table, CarbonImmutable $from, ?CarbonImmutable $until, ?string $environment, array $by, array $only, int $bucketSeconds, AiPriceBook $book): Builder
    {
        $daily = $table === self::DAILY;
        $rows = $this->rows($project, $daily, $from, $until, $environment, $only, $book->boundaries());
        $cost = self::COST;

        $query = DB::query()->fromSub($rows, 'u')
            ->leftJoinSub($this->prices($project, $rows, $book), 'p', fn (JoinClause $join) => $join
                ->on('p.provider', '=', 'u.provider')
                ->on('p.model', '=', 'u.model')
                ->on('p.fingerprint', '=', 'u.fingerprint')
                ->on('p.owner_type', '=', 'u.owner_type')
                ->on('p.owner_id', '=', 'u.owner_id')
                ->on('p.segment', '=', 'u.segment'));

        $groups = [];

        foreach ($by as $dimension) {
            match ($dimension) {
                'bucket' => $query->selectRaw('FLOOR(EXTRACT(EPOCH FROM u.at) / ?)::bigint * ? AS bucket', [$bucketSeconds, $bucketSeconds]),
                'environment' => $query->addSelect('u.environment'),
                'model' => $query->addSelect(['u.provider', 'u.model']),
                'fingerprint' => $query->addSelect('u.fingerprint'),
                'owner' => $query->addSelect(['u.owner_type', 'u.owner_id']),
                'actor' => $query->addSelect(['u.actor_type', 'u.actor_id']),
            };
            array_push($groups, ...match ($dimension) {
                'bucket' => ['bucket'],
                'environment' => ['u.environment'],
                'model' => ['u.provider', 'u.model'],
                'fingerprint' => ['u.fingerprint'],
                'owner' => ['u.owner_type', 'u.owner_id'],
                'actor' => ['u.actor_type', 'u.actor_id'],
            });
        }

        foreach (self::COUNTERS as $counter) {
            $query->selectRaw("COALESCE(SUM(u.{$counter}), 0)::bigint AS {$counter}");
        }

        return $query
            // Input counts cached tokens and output reasoning ones, so this is every token once.
            ->selectRaw('COALESCE(SUM(u.input_tokens + u.output_tokens), 0)::bigint AS tokens')
            ->selectRaw("COALESCE(SUM({$cost}) FILTER (WHERE p.customer = 0), 0)::float AS cost_ours")
            ->selectRaw("COALESCE(SUM({$cost}) FILTER (WHERE p.customer = 1), 0)::float AS cost_customer")
            ->selectRaw("COALESCE(SUM({$cost}), 0)::float AS cost")
            ->selectRaw('COALESCE(SUM(u.input_tokens + u.output_tokens) FILTER (WHERE p.input IS NULL), 0)::bigint AS unpriced_tokens')
            ->selectRaw('COALESCE(SUM(u.input_tokens + u.output_tokens) FILTER (WHERE p.customer = 0), 0)::bigint AS tokens_ours')
            ->selectRaw('COALESCE(SUM(u.input_tokens + u.output_tokens) FILTER (WHERE p.customer = 1), 0)::bigint AS tokens_customer')
            ->when($groups !== [])->groupBy($groups);
    }

    /**
     * The totals matching, each with the time it is priced at (at) and the stretch between
     * two prices' starts it falls in (segment, 0 before the first).
     *
     * @param  Only  $only
     * @param  list<string>  $boundaries
     */
    private function rows(Project $project, bool $daily, CarbonImmutable $from, ?CarbonImmutable $until, ?string $environment, array $only, array $boundaries): Builder
    {
        $column = $daily ? 't.day' : 't.hour';
        $time = $daily ? 't.day::timestamp' : 't.hour';
        // The call's owner, else its key's: a call made with a tenant's key is the tenant's.
        $named = "(t.owner_type <> '' OR k.owner_type IS NULL)";

        return DB::table(($daily ? 'ai_usage_daily' : 'ai_usage_hourly').' as t')
            ->leftJoin('ai_keys as k', fn (JoinClause $join) => $join->where('k.project_id', $project->id)->on('k.fingerprint', '=', 't.fingerprint'))
            ->where('t.project_id', $project->id)
            ->where($column, '>=', $daily ? $from->toDateString() : $from->format('Y-m-d H:i:s'))
            ->when($until !== null)->where($column, '<', $daily ? $until?->toDateString() : $until?->format('Y-m-d H:i:s'))
            ->when($environment !== null)->where('t.environment', $environment)
            ->when(isset($only['fingerprint']))->whereIn('t.fingerprint', (array) ($only['fingerprint'] ?? []))
            // An owner it names, or none and a key of one of the owners: each side from an index,
            // and a row matching both still counted once.
            ->when(isset($only['owner']))->where(fn (Builder $query) => $query
                ->where(fn (Builder $named) => self::whereReferences($named, 't.owner_type', 't.owner_id', $only['owner'] ?? []))
                ->orWhere(fn (Builder $unnamed) => $unnamed->where('t.owner_type', '')->whereIn('t.fingerprint', DB::table('ai_keys')
                    ->where('project_id', $project->id)
                    ->where(fn (Builder $keys) => self::whereReferences($keys, 'owner_type', 'owner_id', $only['owner'] ?? []))
                    ->select('fingerprint'))))
            ->when(isset($only['actor']))->where(fn (Builder $query) => self::whereReferences($query, 't.actor_type', 't.actor_id', $only['actor'] ?? []))
            ->select(['t.environment', 't.provider', 't.model', 't.fingerprint', ...array_map(fn (string $counter): string => 't.'.$counter, self::COUNTERS)])
            ->when($daily)->addSelect(['t.actor_type', 't.actor_id'])
            ->selectRaw("CASE WHEN {$named} THEN t.owner_type ELSE k.owner_type END AS owner_type")
            ->selectRaw("CASE WHEN {$named} THEN t.owner_id ELSE k.owner_id END AS owner_id")
            ->selectRaw("{$time} AS at")
            ->selectRaw($boundaries === [] ? '0 AS segment' : "width_bucket({$time}, ?::timestamp[]) AS segment", $boundaries === [] ? [] : ['{'.implode(',', $boundaries).'}']);
    }

    /**
     * Rows of any of these references, by a type and an id column; none matches nothing.
     *
     * @param  list<array{type: string, id: string}>  $references
     */
    private static function whereReferences(Builder $query, string $type, string $id, array $references): Builder
    {
        if ($references === []) {
            return $query->whereRaw('false');
        }

        foreach ($references as $reference) {
            $query->orWhere(fn (Builder $one) => $one->where($type, $reference['type'])->where($id, $reference['id']));
        }

        return $query;
    }

    /**
     * The price and payer of each provider, model, key and owner in each stretch the rows
     * fall in, as a table to join, sent as one JSON binding. Usage no price applies to (or
     * from before the first) gets a row too, with no price, so its payer is known.
     */
    private function prices(Project $project, Builder $rows, AiPriceBook $book): Builder
    {
        $boundaries = $book->boundaries();
        $combinations = DB::query()->fromSub($rows, 'u')->distinct()
            ->leftJoin('ai_keys as k', fn (JoinClause $join) => $join->where('k.project_id', $project->id)->on('k.fingerprint', '=', 'u.fingerprint'))
            ->select(['u.provider', 'u.model', 'u.fingerprint', 'u.owner_type', 'u.owner_id', 'u.segment', 'k.id as key_id', 'k.payer as key_payer', 'k.owner_type as key_owner_type'])
            ->get();
        $prices = [];

        foreach ($combinations as $combination) {
            $start = $boundaries[(int) $combination->segment - 1] ?? null;
            $price = $start !== null ? $book->resolve($combination->provider, $combination->model, $combination->fingerprint, $combination->owner_type, $combination->owner_id, CarbonImmutable::parse($start)) : null;

            // A call without a key but with an owner was made on the owner's behalf.
            $payer = $combination->key_id !== null
                ? new AiKey()->newFromBuilder(['payer' => $combination->key_payer, 'owner_type' => $combination->key_owner_type])->effectivePayer($project->ai_default_payer)
                : ($combination->owner_type !== '' ? AiPayer::Customer : $project->ai_default_payer);

            $prices[] = [
                'provider' => $combination->provider,
                'model' => $combination->model,
                'fingerprint' => $combination->fingerprint,
                'owner_type' => $combination->owner_type,
                'owner_id' => $combination->owner_id,
                'segment' => (int) $combination->segment,
                'input' => $price?->input,
                'output' => $price?->output,
                'cache_read' => $price?->cache_read,
                'cache_write' => $price?->cache_write,
                'reasoning' => $price?->reasoning,
                'customer' => $payer === AiPayer::Customer ? 1 : 0,
            ];
        }

        // One binding however many there are, so a project with thousands of keys stays within Postgres' 65535.
        return DB::query()->fromRaw(
            'jsonb_to_recordset(?::jsonb) AS v(provider text, model text, fingerprint text, owner_type text, owner_id text, segment int, input numeric, output numeric, cache_read numeric, cache_write numeric, reasoning numeric, customer int)',
            [json_encode($prices, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * @return Row
     */
    private static function row(stdClass $row): array
    {
        $string = fn (string $column): ?string => property_exists($row, $column) ? (string) $row->{$column} : null;

        return [
            'bucket' => property_exists($row, 'bucket') ? (int) $row->bucket : null,
            'environment' => $string('environment'),
            'provider' => $string('provider'),
            'model' => $string('model'),
            'fingerprint' => $string('fingerprint'),
            'owner_type' => $string('owner_type'),
            'owner_id' => $string('owner_id'),
            'actor_type' => $string('actor_type'),
            'actor_id' => $string('actor_id'),
            'calls' => (int) $row->calls,
            'errors' => (int) $row->errors,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'cache_read_tokens' => (int) $row->cache_read_tokens,
            'cache_write_tokens' => (int) $row->cache_write_tokens,
            'reasoning_tokens' => (int) $row->reasoning_tokens,
            'tokens' => (int) $row->tokens,
            'cost_ours' => (float) $row->cost_ours,
            'cost_customer' => (float) $row->cost_customer,
            'cost' => (float) $row->cost,
            'unpriced_tokens' => (int) $row->unpriced_tokens,
            'tokens_ours' => (int) $row->tokens_ours,
            'tokens_customer' => (int) $row->tokens_customer,
        ];
    }
}
