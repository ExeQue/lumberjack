<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Screen;
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
            'name' => ['required', 'string', 'max:60'],
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
