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
        ];
    }

    public function client(): AgentClient
    {
        $client = $this->validated('client');

        return AgentClient::fromHeartbeat(is_array($client) ? $client : null, $this->validated('version'));
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->attributes->get('project');
    }
}
