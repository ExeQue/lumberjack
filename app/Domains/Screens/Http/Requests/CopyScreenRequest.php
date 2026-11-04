<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

/** Another team of the user's, one they may make screens in, to copy a screen to. */
class CopyScreenRequest extends FormRequest
{
    public function screen(): Screen
    {
        /** @var Screen */
        return $this->route('screen');
    }

    public function team(): ?Team
    {
        return Team::query()->where('uuid', $this->string('team')->toString())->first();
    }

    public function authorize(): bool
    {
        $team = $this->team();

        // ponytail: an unknown team is refused like a foreign one, so uuids cannot be probed.
        return $this->user()?->can('view', $this->screen()) === true
            && $team !== null
            && $this->user()->can('create', [Screen::class, $team]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['team' => ['required', 'string', 'uuid']];
    }
}
