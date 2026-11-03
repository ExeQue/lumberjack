<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmTvPairingRequest extends FormRequest
{
    private ?Screen $screen = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'screen' => ['required', 'uuid'],
            // The device's name: asked for its first window only.
            'name' => ['nullable', 'string', 'max:60'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('screen')) {
                    return;
                }

                $screen = Screen::query()->with('team')->where('uuid', $this->string('screen')->toString())->first();

                if ($screen === null || ! $this->user()?->canAdministerTeam($screen->team)) {
                    $validator->errors()->add('screen', __('screens.pairing.not_admin'));

                    return;
                }

                $this->screen = $screen;
                $pairing = TvPairing::query()->where('code', TvPairing::normalizeCode((string) $this->route('code')))->wherePending()->first();

                if (! $this->filled('name') && $pairing?->existingDevice($screen->team_id) === null) {
                    $validator->errors()->add('name', __('screens.pairing.name_required'));
                }
            },
        ];
    }

    /** The picked screen, of a team the user administers. */
    public function screen(): Screen
    {
        /** @var Screen */
        return $this->screen;
    }
}
