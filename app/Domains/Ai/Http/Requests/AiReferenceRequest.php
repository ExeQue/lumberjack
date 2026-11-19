<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Requests;

/**
 * One key owner or actor, by type and id in the query string as the Activity pages take
 * theirs: either may hold any character, a slash included.
 */
class AiReferenceRequest extends AiUsageFiltersRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'type' => ['required', 'string', 'max:128'],
            'id' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array{type: string, id: string}
     */
    public function reference(): array
    {
        return ['type' => $this->string('type')->toString(), 'id' => $this->string('id')->toString()];
    }
}
