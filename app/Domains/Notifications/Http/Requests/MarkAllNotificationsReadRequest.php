<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Requests;

use App\Domains\Administration\Data\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything unread, or everything unread of one kind when the center is filtered by it.
 */
class MarkAllNotificationsReadRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['kind' => ['sometimes', 'nullable', Rule::in(NotificationPreferences::KINDS)]];
    }

    public function kind(): ?string
    {
        return $this->filled('kind') ? $this->string('kind')->toString() : null;
    }
}
