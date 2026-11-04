<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyPushSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['endpoint' => ['required', 'string', 'max:1024']];
    }
}
