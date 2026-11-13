<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Activity pages' filters: any of some actors, any of some subjects (each as
 * "type:id"), and how occurrences are sorted.
 */
class ActivityFiltersRequest extends FormRequest
{
    public const array SORTS = ['time', 'actor'];

    /** Actors or subjects one filter takes at most. */
    public const int MAX_PICKED = 20;

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('view', $project);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $reference = ['string', 'max:200', 'regex:/^[^:]{1,128}:.{1,64}$/'];

        return [
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'actors' => ['nullable', 'array', 'max:'.self::MAX_PICKED],
            'actors.*' => $reference,
            'subjects' => ['nullable', 'array', 'max:'.self::MAX_PICKED],
            'subjects.*' => $reference,
        ];
    }

    public function sort(): string
    {
        return $this->string('sort')->toString() ?: 'time';
    }

    /**
     * @return list<string> as "type:id"
     */
    public function actorKeys(): array
    {
        return $this->picked('actors');
    }

    /**
     * @return list<string> as "type:id"
     */
    public function subjectKeys(): array
    {
        return $this->picked('subjects');
    }

    /**
     * @return list<array{type: string, id: string}>
     */
    public function actors(): array
    {
        return self::references($this->actorKeys());
    }

    /**
     * @return list<array{type: string, id: string}>
     */
    public function subjects(): array
    {
        return self::references($this->subjectKeys());
    }

    /**
     * @return list<string>
     */
    private function picked(string $key): array
    {
        $values = $this->input($key);

        return is_array($values) ? array_values(array_unique(array_filter($values, 'is_string'))) : [];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{type: string, id: string}>
     */
    private static function references(array $keys): array
    {
        return array_map(function (string $key): array {
            [$type, $id] = explode(':', $key, 2);

            return ['type' => $type, 'id' => $id];
        }, $keys);
    }
}
