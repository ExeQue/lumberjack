<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Requests;

use App\Domains\Metrics\Data\MetricFilter;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Metrics\Enums\MetricGroup;
use App\Domains\Metrics\Queries\MetricSeriesQuery;
use App\Domains\Metrics\Support\MetricsCredential;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * A time series, with parameters named after Grafana's macros: from and to as epoch
 * milliseconds or ISO 8601, interval as 30s, 5m, 1h, 1d or auto.
 */
class ReadSeriesRequest extends FormRequest
{
    /** The longest range one series covers; older data is past every default retention. */
    public const int MAX_DAYS = 90;

    /**
     * Times are rounded down to this many seconds, so panels refreshing every few seconds
     * ask the same question and share the cached answer.
     */
    public const int ROUND_SECONDS = 30;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['metric' => $this->route('metric')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $time = ['sometimes', 'string', 'max:40', function (string $attribute, mixed $value, Closure $fail): void {
            if ($this->time((string) $value) === null) {
                $fail("The {$attribute} is neither epoch milliseconds nor an ISO 8601 time.");
            }
        }];

        return [
            'metric' => ['required', Rule::enum(Metric::class)],
            'from' => $time,
            'to' => $time,
            'interval' => ['sometimes', 'string', 'regex:/^(auto|\d{1,6}(ms|s|m|h|d))$/'],
            'environment' => ['sometimes', 'nullable', 'string', 'max:64'],
            'route' => ['sometimes', 'nullable', 'string', 'max:255'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'queue' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job' => ['sometimes', 'nullable', 'string', 'max:255'],
            'host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
            'check' => ['sometimes', 'nullable', 'string', 'max:100'],
            'group_by' => ['sometimes', 'nullable', Rule::enum(MetricGroup::class)],
            'top' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $metric = Metric::tryFrom($this->string('metric')->toString());
            $group = MetricGroup::tryFrom($this->string('group_by')->toString());

            if ($metric !== null && $group !== null && ! in_array($group, $metric->groups(), true)) {
                $validator->errors()->add('group_by', "{$metric->value} cannot be split by {$group->value}.");
            }

            // Request counts carry no host: a host filter there would quietly cover every host.
            if ($metric !== null && $this->filled('host') && ! in_array(MetricGroup::Host, $metric->groups(), true)) {
                $validator->errors()->add('host', "{$metric->value} cannot be filtered by host.");
            }

            // Public stats leave hosts out.
            if (app(MetricsCredential::class)->isPublic() && ($group === MetricGroup::Host || $this->filled('host'))) {
                $validator->errors()->add('host', 'Public stats are not split or filtered by host.');
            }
        }];
    }

    public function metric(): Metric
    {
        return Metric::from($this->string('metric')->toString());
    }

    public function window(): MetricWindow
    {
        $round = fn (CarbonImmutable $at): CarbonImmutable => $at->setTimestamp(intdiv($at->getTimestamp(), self::ROUND_SECONDS) * self::ROUND_SECONDS);
        $to = $round(min(now(), $this->time($this->string('to')->toString()) ?? now()));
        $from = $round($this->time($this->string('from')->toString()) ?? $to->subDay());
        $from = max($from, $to->subDays(self::MAX_DAYS));

        if ($from >= $to) {
            $from = $to->subMinute();
        }

        return MetricWindow::between($from, $to, $this->intervalSeconds());
    }

    public function filter(Project $project): MetricFilter
    {
        $value = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->toString() : null;

        return new MetricFilter(
            projectIds: [$project->id],
            environment: $value('environment'),
            route: $value('route'),
            queue: $value('queue'),
            job: $value('job'),
            host: $value('host'),
            release: $value('release'),
            groupBy: MetricGroup::tryFrom($this->string('group_by')->toString()),
            check: $value('check'),
            domain: $value('domain') !== null ? mb_strtolower((string) $value('domain')) : null,
        );
    }

    public function top(): int
    {
        return $this->integer('top', MetricSeriesQuery::TOP);
    }

    /**
     * @return array<string, mixed>  everything the answer depends on, for the cache key
     */
    public function cacheArgs(): array
    {
        $window = $this->window();

        return [...$this->safe()->except(['from', 'to', 'interval']), 'from' => $window->from->getTimestamp(), 'to' => $window->to->getTimestamp(), 'bucket' => $window->bucketSeconds];
    }

    private function intervalSeconds(): ?int
    {
        $interval = $this->string('interval', 'auto')->toString();

        if (! preg_match('/^(\d+)(ms|s|m|h|d)$/', $interval, $m)) {
            return null;
        }

        return (int) match ($m[2]) {
            'ms' => intdiv((int) $m[1], 1000),
            's' => (int) $m[1],
            'm' => (int) $m[1] * 60,
            'h' => (int) $m[1] * 3600,
            default => (int) $m[1] * 86400,
        };
    }

    private function time(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return CarbonImmutable::createFromTimestampMs((int) $value)->utc();
        }

        try {
            // A + in an unencoded query string arrives as a space.
            return CarbonImmutable::parse(str_replace(' ', '+', $value))->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
