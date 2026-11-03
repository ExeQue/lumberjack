<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Closure;

/**
 * Remembers values per key in memory, keeping only the most recently used ones, so a
 * long-lived worker that walks thousands of keys holds no more than the capacity.
 *
 * @template TValue
 */
final class LruCache
{
    /** @var array<string, TValue> least recently used first */
    private array $items = [];

    public function __construct(private readonly int $capacity = 100)
    {
    }

    /**
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public function remember(string $key, Closure $compute): mixed
    {
        if (array_key_exists($key, $this->items)) {
            $value = $this->items[$key];
            // Move it to the end: the most recently used.
            unset($this->items[$key]);

            return $this->items[$key] = $value;
        }

        $this->items[$key] = $compute();

        if (count($this->items) > $this->capacity) {
            unset($this->items[array_key_first($this->items)]);
        }

        return $this->items[$key];
    }
}
