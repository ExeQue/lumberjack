<?php

declare(strict_types=1);

use App\Domains\DataSources\Support\JsonPath;

covers(JsonPath::class);

beforeEach(function () {
    $this->body = json_decode('{"total": "42.5", "services": [
        {"name": "api", "status": "ok", "latency": 120, "meta": {"region": "eu"}},
        {"name": "worker", "status": "down", "latency": 900, "meta": {"region": "us"}},
        {"name": "web", "status": "ok", "latency": 80, "meta": {"region": "eu"}}
    ]}', true);
});

it('reads one number, also from a numeric string, and from a relative path', function () {
    expect(JsonPath::number($this->body, '$.total'))->toBe(42.5)
        ->and(JsonPath::number($this->body, 'services[1].latency'))->toBe(900.0);
});

it('reads a number through a filter expression', function () {
    expect(JsonPath::number($this->body, "$.services[?(@.status=='down')].latency"))->toBe(900.0)
        ->and(JsonPath::number($this->body, "$.services[?(@.meta.region=='eu' && @.latency<100)].latency"))->toBe(80.0);
});

it('has no number where the path finds none, several, text or does not parse', function (string $path) {
    expect(JsonPath::number($this->body, $path))->toBeNull();
})->with([
    'nothing' => ['$.missing'],
    'several' => ["$.services[?(@.status=='ok')].latency"],
    'text' => ['$.services[0].name'],
    'recursive descent finds several' => ['$..latency'],
    'broken' => ['$.services[?(@.status=='],
]);

it('reads rows from an array or from the matches, with label and value paths', function () {
    expect(JsonPath::rows($this->body, "$.services[?(@.status=='ok')]", '$.name', 'latency', 10))->toBe([
        ['label' => 'api', 'value' => 120.0],
        ['label' => 'web', 'value' => 80.0],
    ])
        ->and(JsonPath::rows($this->body, '$.services', 'name', '$.status', 2))->toBe([
            ['label' => 'api', 'value' => 'ok'],
            ['label' => 'worker', 'value' => 'down'],
        ])
        ->and(JsonPath::rows($this->body, '$..region', null, null, 10))->toBe([
            ['label' => 'eu', 'value' => null],
            ['label' => 'us', 'value' => null],
            ['label' => 'eu', 'value' => null],
        ]);
});

it('matches nothing where evaluating the path fails in any way, or the path is too long', function () {
    $failing = new class () implements ArrayAccess {
        public function offsetExists(mixed $offset): bool
        {
            throw new RuntimeException('broken');
        }

        public function offsetGet(mixed $offset): mixed
        {
            throw new RuntimeException('broken');
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    };

    $long = "$.services[?(@.name=='api'".str_repeat(" || @.name=='api'", 30).')].latency';

    expect(JsonPath::find($failing, '$.total'))->toBe([])
        ->and(JsonPath::number($this->body, substr($long, 0, 26).')].latency'))->toBe(120.0)
        ->and(strlen($long))->toBeGreaterThan(JsonPath::MAX_PATH)
        ->and(JsonPath::find($this->body, $long))->toBe([]);
});

it('gives no more matches and rows than the most', function () {
    $body = ['items' => range(1, 500)];

    expect(JsonPath::find($body, '$.items[*]'))->toHaveCount(JsonPath::MAX_MATCHES)
        ->and(JsonPath::rows($body, '$.items', null, null, 1000))->toHaveCount(JsonPath::MAX_MATCHES);
});
