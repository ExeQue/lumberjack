<?php

declare(strict_types=1);

namespace App\Domains\Administration\Casts;

use App\Domains\Administration\Data\NotificationPreferences;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<NotificationPreferences, NotificationPreferences|null>
 */
class NotificationPreferencesCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): NotificationPreferences
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return NotificationPreferences::fromArray(is_array($decoded) ? $decoded : null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
