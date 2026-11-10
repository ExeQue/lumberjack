<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Requests;

use App\Domains\DataSources\Actions\FetchDataSourceAction;
use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Shared\Rules\PublicUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding or editing a data source. A header's value is write-only: left empty on an edit,
 * the header keeps the value it has, unless the URL now points at another origin.
 */
class SaveDataSourceRequest extends FormRequest
{
    public const int MAX_HEADERS = 20;

    public function source(): ?DataSource
    {
        $source = $this->route('dataSource');

        return $source instanceof DataSource ? $source : null;
    }

    /**
     * A calendar's webcal:// link (what Google, iCloud and Outlook hand out) is the same
     * address over HTTPS.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge(['url' => self::normalizeUrl($this->input('url'))]);
        }
    }

    public static function normalizeUrl(string $url): string
    {
        return (string) preg_replace('~^webcals?://~i', 'https://', trim($url));
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The rules for a source, shared with the MCP tools.
     *
     * @return array<string, array<mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            // The same guard as the health checks Lumberjack runs; fetching checks it again.
            'url' => ['required', 'string', 'url:http,https', 'max:2000', new PublicUrl((bool) config('health.allow_private', false)), function (string $attribute, mixed $value, Closure $fail): void {
                // A password belongs in a header, which is stored encrypted and never shown.
                if (is_string($value) && (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null)) {
                    $fail(__('data_sources.url_credentials'));
                }
            }],
            'format' => ['required', Rule::enum(DataSourceFormat::class)->except(DataSourceFormat::Connected)],
            'interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'timeout_s' => ['required', 'integer', 'min:1', 'max:'.RunHealthCheckAction::MAX_TIMEOUT_S],
            'headers' => ['nullable', 'array', 'max:'.self::MAX_HEADERS],
            // A token as HTTP defines it; Host is Lumberjack's to set.
            'headers.*.name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', 'not_regex:/^host$/i', 'distinct:ignore_case'],
            'headers.*.value' => ['nullable', 'string', 'max:4000', 'not_regex:/[\r\n]/'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => self::checkHeaderValues($validator, $this->source(), $this->input('url'), $this->input('headers', []))];
    }

    /**
     * Each header without a value must be one the source has, and still for the same origin.
     */
    public static function checkHeaderValues(Validator $validator, ?DataSource $source, mixed $url, mixed $headers): void
    {
        $kept = array_map('strtolower', $source?->headerNames() ?? []);
        // A value kept is only ever sent to the origin it was given for.
        $moved = $source !== null && FetchDataSourceAction::origin((string) $url) !== FetchDataSourceAction::origin($source->url);

        foreach ((array) $headers as $i => $header) {
            $name = (string) (is_array($header) ? ($header['name'] ?? '') : '');
            $value = is_array($header) ? ($header['value'] ?? null) : null;

            if ($value !== null && $value !== '') {
                continue;
            }

            if (! in_array(strtolower($name), $kept, true)) {
                $validator->errors()->add("headers.{$i}.value", __('data_sources.header_value_required'));
            } elseif ($moved) {
                $validator->errors()->add("headers.{$i}.value", __('data_sources.header_value_new_origin', ['header' => $name]));
            }
        }
    }
}
