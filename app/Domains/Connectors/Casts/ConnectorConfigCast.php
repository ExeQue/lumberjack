<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Casts;

use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\Mosparo\Data\MosparoConfig;
use App\Domains\Connectors\Umami\Data\UmamiConfig;
use App\Domains\DataSources\Enums\ConnectionKind;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A service connection's settings, credentials included, kept as encrypted JSON and read
 * as the settings of the connection's kind. An app's connection has none.
 *
 * Reading one saved under another APP_KEY throws DecryptException, which is said where it
 * is used rather than breaking the page.
 *
 * @implements CastsAttributes<UmamiConfig|MosparoConfig|GitHubConfig|null, UmamiConfig|MosparoConfig|GitHubConfig|null>
 */
class ConnectorConfigCast implements CastsAttributes
{
    /**
     * @throws DecryptException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): UmamiConfig|MosparoConfig|GitHubConfig|null
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $data = json_decode(Crypt::decryptString($value), true);
        $data = is_array($data) ? $data : [];
        $kind = $attributes['kind'] ?? null;

        return match ($kind instanceof ConnectionKind ? $kind : ConnectionKind::tryFrom((string) $kind)) {
            ConnectionKind::Umami => UmamiConfig::fromArray($data),
            ConnectionKind::Mosparo => MosparoConfig::fromArray($data),
            ConnectionKind::GitHub => GitHubConfig::fromArray($data),
            default => null,
        };
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString(json_encode($value->toArray(), JSON_THROW_ON_ERROR));
    }
}
