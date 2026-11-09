<?php

declare(strict_types=1);

namespace App\Domains\Health\Http\Requests;

use App\Domains\DataSources\Support\JsonPath;
use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Support\SmtpProbe;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding or editing a check. A check Lumberjack runs takes its type's settings; one
 * the app reports only takes how it is shown and alerted on.
 */
class SaveHealthCheckRequest extends FormRequest
{
    /** @var array<string, list<string>> the settings each type keeps */
    public const array CONFIG_KEYS = [
        'http' => ['url', 'method', 'expected_status', 'body_contains', 'json_path', 'json_expected', 'max_latency_ms', 'timeout_s'],
        'ssl' => ['host', 'port', 'warn_days', 'fail_days'],
        'tcp' => ['host', 'port', 'timeout_s'],
        'dns' => ['name', 'record_type', 'expected'],
        'heartbeat' => ['grace_minutes'],
        'domain' => ['domain', 'warn_days', 'fail_days'],
        'smtp' => ['host', 'port', 'encryption', 'timeout_s'],
    ];

    private const string HOST = 'regex:/^[A-Za-z0-9.-]{1,253}$|^\[?[0-9A-Fa-f:.]+\]?$/';

    public function check(): ?HealthCheck
    {
        $check = $this->route('healthCheck');

        return $check instanceof HealthCheck ? $check : null;
    }

    public function type(): ?HealthCheckType
    {
        return $this->check()->type ?? HealthCheckType::tryFrom((string) $this->input('type'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $reported = $this->check()?->isReported() ?? false;
        $thresholds = [$reported ? 'nullable' : 'required', 'integer', 'min:1', 'max:100'];
        $common = [
            // A heartbeat has no settings to take a name from.
            'name' => [$this->type() === HealthCheckType::Heartbeat ? 'required' : 'nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'degrade_after' => $thresholds,
            'recover_after' => $thresholds,
            'down_after' => $thresholds,
            'muted' => ['boolean'],
        ];

        if ($reported) {
            return $common;
        }

        return [
            ...$common,
            'type' => [$this->check() === null ? 'required' : 'nullable', Rule::enum(HealthCheckType::class)],
            'environment' => ['nullable', 'string', 'max:64'],
            'interval_minutes' => ['required', 'integer', Rule::in(HealthCheck::INTERVALS)],
            'config' => ['nullable', 'array'],
            ...$this->configRules(),
        ];
    }

    /**
     * Down after fewer failures than degraded would skip degraded in a way nobody asked
     * for; setting both the same is how to go straight to down.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->route('project');
            $max = (int) config('health.max_checks_per_project', 50);

            if ($this->check() === null && $project instanceof Project && $project->healthChecks()->whereMonitored()->count() >= $max) {
                $validator->errors()->add('type', __('health.too_many_checks', ['max' => $max]));
            }

            $down = $this->input('down_after');
            $degrade = $this->input('degrade_after') ?? $this->check()?->degrade_after;

            if (is_numeric($down) && is_numeric($degrade) && (int) $down < (int) $degrade) {
                $validator->errors()->add('down_after', __('health.down_after_too_low', ['degrade' => (int) $degrade]));
            }
        }];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function configRules(): array
    {
        return match ($this->type()) {
            HealthCheckType::Http => [
                'config.url' => ['required', 'url:http,https', 'max:2000'],
                'config.method' => ['nullable', Rule::in(['GET', 'HEAD', 'POST'])],
                'config.expected_status' => ['nullable', 'integer', 'min:100', 'max:599'],
                'config.body_contains' => ['nullable', 'string', 'max:200'],
                'config.json_path' => ['nullable', 'string', 'max:'.JsonPath::MAX_PATH],
                'config.json_expected' => ['nullable', 'string', 'max:200'],
                'config.max_latency_ms' => ['nullable', 'integer', 'min:1', 'max:60000'],
                'config.timeout_s' => ['nullable', 'integer', 'min:1', 'max:'.RunHealthCheckAction::MAX_TIMEOUT_S],
            ],
            HealthCheckType::Ssl => [
                'config.host' => ['required', 'string', self::HOST],
                'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'config.warn_days' => ['nullable', 'integer', 'min:1', 'max:365'],
                'config.fail_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            ],
            HealthCheckType::Tcp => [
                'config.host' => ['required', 'string', self::HOST],
                'config.port' => ['required', 'integer', 'min:1', 'max:65535'],
                'config.timeout_s' => ['nullable', 'integer', 'min:1', 'max:'.RunHealthCheckAction::MAX_TIMEOUT_S],
            ],
            HealthCheckType::Domain => [
                'config.domain' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/'],
                'config.warn_days' => ['nullable', 'integer', 'min:1', 'max:365'],
                'config.fail_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            ],
            HealthCheckType::Smtp => [
                'config.host' => ['required', 'string', self::HOST],
                'config.port' => ['required', 'integer', 'min:1', 'max:65535'],
                'config.encryption' => ['nullable', Rule::in([SmtpProbe::ENCRYPTION_STARTTLS, SmtpProbe::ENCRYPTION_TLS, SmtpProbe::ENCRYPTION_NONE])],
                'config.timeout_s' => ['nullable', 'integer', 'min:1', 'max:'.RunHealthCheckAction::MAX_TIMEOUT_S],
            ],
            HealthCheckType::Dns => [
                'config.name' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9._-]+$/'],
                'config.record_type' => ['required', Rule::in(['A', 'AAAA', 'CNAME', 'MX', 'TXT'])],
                'config.expected' => ['nullable', 'string', 'max:255'],
            ],
            HealthCheckType::Heartbeat => [
                'config.grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            ],
            null => [],
        };
    }

    /**
     * The type's settings as entered, empty ones left out so their defaults apply.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $given = (array) $this->validated('config', []);
        $keys = self::CONFIG_KEYS[$this->type()?->value] ?? [];

        return array_filter(array_intersect_key($given, array_flip($keys)), fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
