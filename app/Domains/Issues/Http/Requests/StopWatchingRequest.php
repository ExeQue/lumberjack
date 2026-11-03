<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The one-click stop from a watching mail: no sign-in, the signed URL is the proof
 * (the route's signed middleware checks it, a tampered link never gets here).
 */
class StopWatchingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['user' => ['required', 'uuid']];
    }

    public function watcher(): User
    {
        return User::query()->where('uuid', $this->string('user')->toString())->firstOrFail();
    }
}
