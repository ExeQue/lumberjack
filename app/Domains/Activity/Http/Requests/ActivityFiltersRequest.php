<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Activity pages' filters: one actor, one subject, and how occurrences are sorted.
 */
class ActivityFiltersRequest extends FormRequest
{
    public const array SORTS = ['time', 'actor'];

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('view', $project);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'actor_type' => ['nullable', 'string', 'max:128', 'required_with:actor_id'],
            'actor_id' => ['nullable', 'string', 'max:64', 'required_with:actor_type'],
            'subject_type' => ['nullable', 'string', 'max:128', 'required_with:subject_id'],
            'subject_id' => ['nullable', 'string', 'max:64', 'required_with:subject_type'],
        ];
    }

    public function sort(): string
    {
        return $this->string('sort')->toString() ?: 'time';
    }

    /**
     * @return array{type: string, id: string}|null
     */
    public function actor(): ?array
    {
        return $this->reference('actor');
    }

    /**
     * @return array{type: string, id: string}|null
     */
    public function subject(): ?array
    {
        return $this->reference('subject');
    }

    /**
     * @return array{type: string, id: string}|null
     */
    private function reference(string $prefix): ?array
    {
        $type = $this->string($prefix.'_type')->toString();
        $id = $this->string($prefix.'_id')->toString();

        return $type !== '' && $id !== '' ? ['type' => $type, 'id' => $id] : null;
    }
}
