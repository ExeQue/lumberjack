<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Http\Requests;

/**
 * The credentials and the website picked from those they can read.
 */
class ConnectUmamiRequest extends UmamiCredentialsRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...self::credentialRules(),
            'website_id' => ['required', 'string', 'max:64'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all_with_bc'],
        ];
    }
}
