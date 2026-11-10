<?php

declare(strict_types=1);

namespace Tests\Support\Mcp;

use Illuminate\Testing\TestResponse;

trait CallsMcpTools
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function callTool(string $token, string $tool, array $arguments = []): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ], ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream']);
    }

    /**
     * The decoded JSON a tool returned as its text content.
     *
     * @return array<string, mixed>
     */
    public function toolResult(TestResponse $response): array
    {
        $text = $response->json('result.content.0.text');

        return is_string($text) ? (array) json_decode($text, true) : [];
    }

    /**
     * Whether the server refused the call: a tool error, or a tool the token cannot see.
     */
    public function wasRefused(TestResponse $response): bool
    {
        return $response->json('result.isError') === true || $response->json('error') !== null;
    }
}
