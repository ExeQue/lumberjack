<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Requests;

use App\Domains\Teams\Enums\TeamRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;

        return $team !== null && $this->user()->canAdministerTeam($team);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(TeamRole::class)->except([TeamRole::Owner])]];
    }
}
