<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Requests;

use App\Domains\Projects\Models\Project;
use Override;

/**
 * A new address or time zone for an app. Its token, sources and widgets stay.
 */
class UpdateDataSourceConnectionRequest extends SaveDataSourceConnectionRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    #[Override]
    public static function rulesFor(Project $project): array
    {
        return ['url' => self::urlRules(), 'timezone' => ['sometimes', 'required', 'string', 'timezone:all_with_bc']];
    }

    #[Override]
    public function after(): array
    {
        return [];
    }
}
