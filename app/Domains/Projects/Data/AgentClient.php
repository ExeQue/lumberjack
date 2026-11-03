<?php

declare(strict_types=1);

namespace App\Domains\Projects\Data;

use App\Domains\Projects\Models\ProjectAgent;

/**
 * What an agent says it is: the SDK it serves (e.g. lumberjack.php.laravel), the
 * agent itself, and the runtime and framework underneath, each with a version.
 */
final readonly class AgentClient
{
    /** Before agents described themselves, every one was the Laravel SDK's. */
    public const string LEGACY_SDK = 'lumberjack.php.laravel';

    public function __construct(
        public ?string $sdk = null,
        public ?string $sdkVersion = null,
        public ?string $agent = null,
        public ?string $agentVersion = null,
        public ?string $runtime = null,
        public ?string $runtimeVersion = null,
        public ?string $framework = null,
        public ?string $frameworkVersion = null,
    ) {
    }

    /**
     * From a heartbeat's client block, or only the version an older agent sends.
     *
     * @param  array<string, mixed>|null  $client
     */
    public static function fromHeartbeat(?array $client, ?string $version = null): self
    {
        $part = fn (string $key, string $field): ?string => is_string($client[$key][$field] ?? null) ? $client[$key][$field] : null;

        if ($client === null || $client === []) {
            return new self(sdk: $version !== null ? self::LEGACY_SDK : null, sdkVersion: $version);
        }

        return new self(
            $part('sdk', 'name'),
            $part('sdk', 'version'),
            $part('agent', 'name'),
            $part('agent', 'version'),
            $part('runtime', 'name'),
            $part('runtime', 'version'),
            $part('framework', 'name'),
            $part('framework', 'version'),
        );
    }

    /** Product names for the ids agents send; others are shown as sent. */
    private const array NAMES = [
        'lumberjack.php.laravel' => 'Laravel',
        'lumberjack.js.browser' => 'Browser',
        'php' => 'PHP',
        'node' => 'Node.js',
        'python' => 'Python',
    ];

    public static function fromAgent(ProjectAgent $agent): self
    {
        return new self(
            $agent->sdk,
            $agent->sdk_version,
            $agent->agent,
            $agent->agent_version,
            $agent->runtime,
            $agent->runtime_version,
            $agent->framework,
            $agent->framework_version,
        );
    }

    /**
     * "Laravel 0.2.0 · PHP 8.5.1": the SDK and the runtime, for people.
     */
    public function label(): ?string
    {
        $part = fn (?string $id, ?string $version): ?string => $id === null ? null : trim((self::NAMES[$id] ?? $id).' '.$version);

        return implode(' · ', array_filter([$part($this->sdk, $this->sdkVersion), $part($this->runtime, $this->runtimeVersion)])) ?: null;
    }

    /**
     * Every part with its id, for a tooltip.
     */
    public function details(): ?string
    {
        $parts = array_filter([
            $this->sdk !== null ? "{$this->sdk} {$this->sdkVersion}" : null,
            $this->agent !== null ? "{$this->agent} {$this->agentVersion}" : null,
            $this->runtime !== null ? "{$this->runtime} {$this->runtimeVersion}" : null,
            $this->framework !== null ? "{$this->framework} {$this->frameworkVersion}" : null,
        ]);

        return $parts === [] ? null : implode("\n", array_map('trim', $parts));
    }

    /**
     * @return array<string, string|null>
     */
    public function toColumns(): array
    {
        return [
            'sdk' => $this->sdk,
            'sdk_version' => $this->sdkVersion,
            'agent' => $this->agent,
            'agent_version' => $this->agentVersion,
            'runtime' => $this->runtime,
            'runtime_version' => $this->runtimeVersion,
            'framework' => $this->framework,
            'framework_version' => $this->frameworkVersion,
        ];
    }
}
