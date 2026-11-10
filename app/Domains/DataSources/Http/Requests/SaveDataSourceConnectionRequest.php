<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Requests;

use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Rules\PublicUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Where an app's data sources are. The address is signed as it stands, so it can hold no
 * query of its own, and no password.
 */
class SaveDataSourceConnectionRequest extends FormRequest
{
    public const int MAX_PER_PROJECT = 20;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) === true;
    }

    /** The app's own address has no trailing slash, and the path is signed as it stands. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge(['url' => self::normalizeUrl($this->input('url'))]);
        }
    }

    public static function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return static::rulesFor($project);
    }

    /**
     * The rules for a connection of the project, shared with the MCP tools.
     *
     * @return array<string, array<mixed>>
     */
    public static function rulesFor(Project $project): array
    {
        return [
            'environment' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ConnectionKind::ownsEnvironment($value)) {
                    $fail(__('validation.not_in', ['attribute' => $attribute]));
                }
            }, Rule::unique('data_source_connections')->where('project_id', $project->id)],
            'url' => self::urlRules(),
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all_with_bc'],
        ];
    }

    /**
     * @return array<mixed>
     */
    protected static function urlRules(): array
    {
        return ['required', 'string', 'url:https', 'max:2000', new PublicUrl((bool) config('health.data_sources_allow_private', false)), function (string $attribute, mixed $value, Closure $fail): void {
            $parts = is_string($value) ? parse_url($value) : false;

            if (is_array($parts) && (isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']))) {
                $fail(__('data_sources.url_plain'));
            }
        }];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [fn (Validator $validator) => self::checkLimit($validator, $project)];
    }

    public static function checkLimit(Validator $validator, Project $project): void
    {
        if ($project->dataSourceConnections()->count() >= self::MAX_PER_PROJECT) {
            $validator->errors()->add('url', __('data_sources.too_many_connections', ['max' => self::MAX_PER_PROJECT]));
        }
    }
}
