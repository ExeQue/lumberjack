<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The AI usage pages' filters besides period and environment: a search and a page per
 * table, and whether a key's calls are only the failed ones.
 */
class AiUsageFiltersRequest extends FormRequest
{
    /** The tables of the AI usage page, each with its own search and page in the query. */
    public const array TABLES = ['keys', 'owners', 'actors'];

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('view', $project);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['errors' => ['nullable', 'boolean']];

        foreach (self::TABLES as $table) {
            $rules[$table.'_search'] = ['nullable', 'string', 'max:200'];
            $rules[$table.'_page'] = ['nullable', 'integer', 'min:1', 'max:1000000'];
        }

        return $rules;
    }

    /**
     * @param  'keys'|'owners'|'actors'  $table
     */
    public function search(string $table): string
    {
        return trim($this->string($table.'_search')->toString());
    }

    public function onlyErrors(): bool
    {
        return $this->boolean('errors');
    }
}
