<?php

declare(strict_types=1);

namespace Tests\Support\Ingest;

trait BuildsIngestPayloads
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public function batch(array $events, string $environment = 'production'): array
    {
        return ['v' => 1, 'environment' => $environment, 'release' => 'a1f3c9e', 'host' => 'web-1', 'events' => $events];
    }

    /**
     * @return array<string, mixed>
     */
    public function exceptionEvent(string $class = 'RuntimeException', string $message = 'Boom', int $line = 42): array
    {
        return [
            'type' => 'exception',
            'trace_id' => 'trace-1',
            'timestamp' => now()->getTimestamp() + 0.123,
            'class' => $class,
            'message' => $message,
            'file' => 'app/Domains/Media/Actions/StoreMediaAction.php',
            'line' => $line,
            'stacktrace' => [
                ['file' => 'vendor/laravel/framework/src/Foo.php', 'line' => 10, 'in_app' => false],
                ['file' => 'app/Domains/Media/Actions/StoreMediaAction.php', 'line' => $line, 'function' => 'execute', 'in_app' => true],
            ],
            'context' => ['request' => ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer secret']], 'user' => ['id' => 7]],
        ];
    }

    /**
     * A browser error thrown in the minified bundle of tests/Fixtures/SourceMaps/app.js.map,
     * at the positions of its throw and of the call that led to it.
     *
     * @return array<string, mixed>
     */
    public function minifiedBrowserError(string $bundleUrl = 'https://app.example.test/build/assets/app-BxQ3kZ9a.js'): array
    {
        return [
            'type' => 'exception',
            'timestamp' => now()->getTimestamp(),
            'class' => 'TypeError',
            'message' => 'Cannot read 42',
            'file' => $bundleUrl,
            'line' => 1,
            'stacktrace' => [
                ['file' => $bundleUrl, 'line' => 1, 'column' => 21, 'function' => 'o', 'in_app' => true],
                ['file' => $bundleUrl, 'line' => 1, 'column' => 128, 'function' => 'i', 'in_app' => true],
            ],
        ];
    }
}
