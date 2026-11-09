<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Requests;

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
            $this->merge(['url' => rtrim(trim($this->input('url')), '/')]);
        }
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'environment' => ['required', 'string', 'max:64', Rule::unique('data_source_connections')->where('project_id', $project->id)],
            'url' => $this->urlRules(),
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all_with_bc'],
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function urlRules(): array
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
        return [function (Validator $validator): void {
            /** @var Project $project */
            $project = $this->route('project');

            if ($project->dataSourceConnections()->count() >= self::MAX_PER_PROJECT) {
                $validator->errors()->add('url', __('data_sources.too_many_connections', ['max' => self::MAX_PER_PROJECT]));
            }
        }];
    }
}
