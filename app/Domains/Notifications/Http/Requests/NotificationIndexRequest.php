<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Requests;

use App\Domains\Administration\Data\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationIndexRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'kind' => ['sometimes', 'nullable', Rule::in(NotificationPreferences::KINDS)],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function kind(): ?string
    {
        return $this->filled('kind') ? $this->string('kind')->toString() : null;
    }
}
