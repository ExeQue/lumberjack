<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Http\Requests\Umami;

use App\Domains\Connectors\Data\Umami\UmamiCredentials;
use App\Domains\Connectors\Enums\Umami\UmamiHosting;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Rules\PublicUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How to reach an Umami instance: the cloud with an API key, or a self-hosted instance's
 * https address with an API key or a user name and password.
 */
class UmamiCredentialsRequest extends FormRequest
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
        return self::credentialRules();
    }

    /**
     * The rules for credentials, shared with the MCP tools.
     *
     * @return array<string, array<mixed>>
     */
    public static function credentialRules(): array
    {
        return [
            'hosting' => ['required', Rule::enum(UmamiHosting::class)],
            'url' => ['exclude_unless:hosting,self_hosted', 'required', 'string', 'url:https', 'max:2000', new PublicUrl((bool) config('health.data_sources_allow_private', false))],
            'username' => ['nullable', 'string', 'max:200'],
            'secret' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function credentialsFrom(array $input): UmamiCredentials
    {
        $hosting = UmamiHosting::from((string) $input['hosting']);
        $username = $hosting === UmamiHosting::SelfHosted && is_string($input['username'] ?? null) && $input['username'] !== '' ? $input['username'] : null;

        return new UmamiCredentials($hosting->apiUrl(is_string($input['url'] ?? null) ? $input['url'] : null), $username, (string) $input['secret']);
    }

    public function credentials(): UmamiCredentials
    {
        return self::credentialsFrom($this->validated());
    }
}
