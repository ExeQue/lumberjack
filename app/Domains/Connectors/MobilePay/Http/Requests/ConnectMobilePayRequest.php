<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Http\Requests;

use App\Domains\Connectors\MobilePay\Data\MobilePayCredentials;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A sales unit's API keys from the business portal, and the time zone its ledger dates
 * begin in.
 */
class ConnectMobilePayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:200'],
            'client_secret' => ['required', 'string', 'max:1000'],
            'subscription_key' => ['required', 'string', 'max:200'],
            'msn' => ['required', 'string', 'max:20', 'regex:/^\d+$/'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all_with_bc'],
        ];
    }

    public function credentials(): MobilePayCredentials
    {
        return new MobilePayCredentials(
            $this->string('client_id')->trim()->toString(),
            $this->string('client_secret')->trim()->toString(),
            $this->string('subscription_key')->trim()->toString(),
            $this->string('msn')->trim()->toString(),
        );
    }
}
