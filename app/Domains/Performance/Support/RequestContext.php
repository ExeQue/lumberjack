<?php

declare(strict_types=1);

namespace App\Domains\Performance\Support;

/**
 * What the SDK knows about a request beyond its route, cut to the fields the server shows
 * and to a bounded size: the client sends it, so nothing else and nothing longer is kept.
 *
 * @phpstan-type LivewireComponent array{name: string, class: string|null, calls: list<string>, updates: int}
 * @phpstan-type Context array{livewire?: array{components: list<LivewireComponent>, page: string|null}, inertia?: array{component: string|null, partial: bool, only: list<string>, except: list<string>, reset: list<string>, prefetch: bool, version_mismatch: bool}}
 */
final class RequestContext
{
    public const int MAX_ITEMS = 50;

    public const int MAX_LENGTH = 255;

    /**
     * @return Context|null
     */
    public static function fromEvent(mixed $context): ?array
    {
        if (! is_array($context)) {
            return null;
        }

        $livewire = is_array($context['livewire'] ?? null) ? $context['livewire'] : null;
        $inertia = is_array($context['inertia'] ?? null) ? $context['inertia'] : null;

        $result = [];

        if ($livewire !== null) {
            $components = is_array($livewire['components'] ?? null) ? array_slice(array_values($livewire['components']), 0, self::MAX_ITEMS) : [];

            $result['livewire'] = [
                'components' => array_values(array_filter(array_map(fn (mixed $component): ?array => is_array($component) && self::string($component['name'] ?? null) !== null ? [
                    'name' => (string) self::string($component['name']),
                    'class' => self::string($component['class'] ?? null),
                    'calls' => self::strings($component['calls'] ?? null),
                    'updates' => max(0, (int) (is_numeric($component['updates'] ?? null) ? $component['updates'] : 0)),
                ] : null, $components))),
                'page' => self::string($livewire['page'] ?? null),
            ];
        }

        if ($inertia !== null) {
            $result['inertia'] = [
                'component' => self::string($inertia['component'] ?? null),
                'partial' => ($inertia['partial'] ?? false) === true,
                'only' => self::strings($inertia['only'] ?? null),
                'except' => self::strings($inertia['except'] ?? null),
                'reset' => self::strings($inertia['reset'] ?? null),
                'prefetch' => ($inertia['prefetch'] ?? false) === true,
                'version_mismatch' => ($inertia['version_mismatch'] ?? false) === true,
            ];
        }

        return $result === [] ? null : $result;
    }

    /** The context as the JSON a bulk insert writes, or null when there is none. */
    public static function json(mixed $context): ?string
    {
        $context = self::fromEvent($context);

        return $context !== null ? json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, self::MAX_LENGTH) : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(self::string(...), array_slice(array_values($values), 0, self::MAX_ITEMS)), fn (?string $value): bool => $value !== null));
    }
}
