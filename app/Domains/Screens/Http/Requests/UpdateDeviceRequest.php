<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function device(): Device
    {
        /** @var Device */
        return $this->route('device');
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->device()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }

    /** @return array{name: string} */
    public function settings(): array
    {
        return ['name' => $this->string('name')->trim()->toString()];
    }
}
