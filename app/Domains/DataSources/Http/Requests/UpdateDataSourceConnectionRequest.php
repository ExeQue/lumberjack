<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Requests;

/**
 * A new address or time zone for an app. Its token, sources and widgets stay.
 */
class UpdateDataSourceConnectionRequest extends SaveDataSourceConnectionRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return ['url' => $this->urlRules(), 'timezone' => ['sometimes', 'required', 'string', 'timezone:all_with_bc']];
    }

    public function after(): array
    {
        return [];
    }
}
