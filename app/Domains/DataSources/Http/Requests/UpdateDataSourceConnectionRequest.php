<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Requests;

/**
 * A new address for an app that moved. Its token, sources and widgets stay.
 */
class UpdateDataSourceConnectionRequest extends SaveDataSourceConnectionRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return ['url' => $this->urlRules()];
    }

    public function after(): array
    {
        return [];
    }
}
