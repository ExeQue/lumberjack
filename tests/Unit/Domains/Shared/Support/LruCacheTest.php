<?php

declare(strict_types=1);

use App\Domains\Shared\Support\LruCache;

covers(LruCache::class);

it('computes a key once and forgets the least recently used past its capacity', function () {
    $cache = new LruCache(2);
    $calls = 0;
    $compute = function () use (&$calls): int {
        return ++$calls;
    };

    $cache->remember('a', $compute);
    $cache->remember('b', $compute);
    // Using "a" again makes "b" the least recently used, so "c" pushes "b" out.
    expect($cache->remember('a', $compute))->toBe(1);
    $cache->remember('c', $compute);

    expect($cache->remember('a', $compute))->toBe(1)
        ->and($cache->remember('b', $compute))->toBe(4)
        ->and($calls)->toBe(4);
});
