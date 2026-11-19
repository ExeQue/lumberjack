<?php

declare(strict_types=1);

use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Support\AiPriceBook;
use Carbon\CarbonImmutable;
use Tests\Support\Ai\BuildsAiPrices;

covers(AiPriceBook::class);

uses(BuildsAiPrices::class);

describe('which price applies', function () {
    it('picks the price that applies to a call', function (array $prices, array $call, ?int $expected) {
        $book = AiPriceBook::of(array_map(fn (array $attributes): AiPrice => $this->aiPrice($attributes), $prices));
        $built = $book->prices();

        $found = $book->resolve(
            $call['provider'] ?? 'anthropic',
            $call['model'] ?? 'claude-sonnet-4-5',
            $call['fingerprint'] ?? 'fp-1',
            $call['owner'][0] ?? null,
            $call['owner'][1] ?? null,
            CarbonImmutable::parse($call['at'] ?? '2026-03-01 00:00:00'),
        );

        // The book sorts its prices; find the expected one by what it was given as.
        expect($found)->toBe($expected === null ? null : collect($built)->first(fn (AiPrice $p): bool => $p->input === (float) $prices[$expected]['input']));
    })->with([
        'a key beats its owner, the project and the team' => [[
            ['project_id' => null, 'input' => 10],
            ['input' => 11],
            ['scope' => 'owner', 'scope_type' => 'tenant', 'scope_id' => '7', 'input' => 12],
            ['scope' => 'key', 'scope_id' => 'fp-1', 'input' => 13],
        ], ['owner' => ['tenant', '7']], 3],
        'an owner beats the project and the team' => [[
            ['project_id' => null, 'input' => 10],
            ['input' => 11],
            ['scope' => 'owner', 'scope_type' => 'tenant', 'scope_id' => '7', 'input' => 12],
        ], ['owner' => ['tenant', '7']], 2],
        'the project beats the team' => [[
            ['project_id' => null, 'input' => 10],
            ['input' => 11],
        ], [], 1],
        'another key\'s or owner\'s price does not apply' => [[
            ['project_id' => null, 'input' => 10],
            ['scope' => 'owner', 'scope_type' => 'tenant', 'scope_id' => '8', 'input' => 12],
            ['scope' => 'key', 'scope_id' => 'fp-2', 'input' => 13],
        ], ['owner' => ['tenant', '7']], 0],
        'an exact model beats a glob' => [[
            ['model_pattern' => 'claude-*', 'input' => 10],
            ['model_pattern' => 'claude-sonnet-4-5', 'input' => 11],
        ], [], 1],
        'a longer glob beats a shorter one' => [[
            ['model_pattern' => 'claude-*', 'input' => 10],
            ['model_pattern' => 'claude-sonnet-*', 'input' => 11],
        ], [], 1],
        'a narrower glob beats a broader exact model' => [[
            ['project_id' => null, 'model_pattern' => 'claude-sonnet-4-5', 'input' => 10],
            ['model_pattern' => '*', 'input' => 11],
        ], [], 1],
        'a glob matches only its models' => [[
            ['model_pattern' => 'gpt-*', 'input' => 10],
        ], [], null],
        'a provider\'s price beats one for any provider' => [[
            ['provider' => null, 'input' => 10],
            ['provider' => 'anthropic', 'input' => 11],
        ], [], 1],
        'another provider\'s price does not apply' => [[
            ['provider' => 'openai', 'input' => 10],
        ], [], null],
        'the latest price valid at the time wins' => [[
            ['valid_from' => '2026-01-01 00:00:00', 'input' => 10],
            ['valid_from' => '2026-02-01 00:00:00', 'input' => 11],
            ['valid_from' => '2026-06-01 00:00:00', 'input' => 12],
        ], ['at' => '2026-03-01 00:00:00'], 1],
        'a price applies from its very start' => [[
            ['valid_from' => '2026-01-01 00:00:00', 'input' => 10],
            ['valid_from' => '2026-03-01 00:00:00', 'input' => 11],
        ], ['at' => '2026-03-01 00:00:00'], 1],
        'a narrower price not valid yet leaves the broader one' => [[
            ['project_id' => null, 'valid_from' => '2026-01-01 00:00:00', 'input' => 10],
            ['scope' => 'key', 'scope_id' => 'fp-1', 'valid_from' => '2026-06-01 00:00:00', 'input' => 11],
        ], ['at' => '2026-03-01 00:00:00'], 0],
        'nothing applies before the first price' => [[
            ['valid_from' => '2026-06-01 00:00:00', 'input' => 10],
        ], ['at' => '2026-03-01 00:00:00'], null],
    ]);

    it('names every moment a price starts, once and in order', function () {
        $book = AiPriceBook::of([
            $this->aiPrice(['valid_from' => '2026-06-01 00:00:00']),
            $this->aiPrice(['valid_from' => '2026-01-01 00:00:00']),
            $this->aiPrice(['valid_from' => '2026-06-01 00:00:00', 'model_pattern' => '*']),
        ]);

        expect($book->boundaries())->toBe(['2026-01-01 00:00:00', '2026-06-01 00:00:00']);
    });
});
