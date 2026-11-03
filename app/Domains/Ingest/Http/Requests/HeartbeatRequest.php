<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Requests;

use App\Domains\Projects\Data\AgentClient;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('project') instanceof Project;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            // Sent by agents before they described themselves; the SDK's version.
            'version' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:64'],
            'client' => ['nullable', 'array:sdk,agent,runtime,framework'],
            'client.sdk.name' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9.-]*$/'],
            'client.agent.name' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9.-]*$/'],
            'client.runtime.name' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9.-]*$/'],
            'client.framework.name' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9.-]*$/'],
            'client.*.version' => ['nullable', 'string', 'max:32'],
            'queues' => ['nullable', 'array', 'max:50'],
            'queues.*.connection' => ['required', 'string', 'max:64'],
            'queues.*.queue' => ['required', 'string', 'max:255'],
            'queues.*.size' => ['required', 'integer', 'min:0'],
            'queues.*.oldest_age_s' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function client(): AgentClient
    {
        $client = $this->validated('client');

        return AgentClient::fromHeartbeat(is_array($client) ? $client : null, $this->validated('version'));
    }

    /**
     * @return list<array{connection: string, queue: string, size: int, oldest_age_s: int|null}>
     */
    public function queues(): array
    {
        return array_values(array_map(fn (array $q): array => [
            'connection' => (string) $q['connection'],
            'queue' => (string) $q['queue'],
            'size' => (int) $q['size'],
            'oldest_age_s' => isset($q['oldest_age_s']) ? (int) $q['oldest_age_s'] : null,
        ], (array) $this->validated('queues', [])));
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->attributes->get('project');
    }
}
