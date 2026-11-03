<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return self::allowed($this->user());
    }

    /**
     * lumberjack.teams.allow_creation: everyone, or system administrators only.
     */
    public static function allowed(?User $user): bool
    {
        return $user !== null && (config('lumberjack.teams.allow_creation') !== 'admins' || $user->isAdmin());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100']];
    }
}
