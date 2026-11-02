<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Requests;

use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Support\ChannelDriverRegistry;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveNotificationChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;

        if ($team === null) {
            return false;
        }

        // A project's own channel takes administering that project; a shared one, the team.
        $project = $this->project();

        return $project !== null ? $this->user()->can('update', $project) : $this->user()->canAdministerTeam($team);
    }

    /**
     * The project of the channel: the one given when adding, or the one it has when editing.
     */
    public function project(): ?Project
    {
        $channel = $this->route('channel');

        if ($channel instanceof NotificationChannel) {
            return $channel->project;
        }

        $uuid = $this->input('project');

        return is_string($uuid) && $uuid !== '' ? $this->user()?->currentTeam?->projects()->where('uuid', $uuid)->first() : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = ChannelType::tryFrom((string) $this->input('type'));
        $configRules = [];

        // Editing keeps a stored secret (a webhook URL) when its field is left empty.
        $editing = $this->route('channel') instanceof NotificationChannel;

        if ($type !== null) {
            foreach (app(ChannelDriverRegistry::class)->for($type)->rules() as $field => $rules) {
                $configRules['config.'.$field] = $editing ? ['nullable', ...array_filter($rules, fn ($r): bool => $r !== 'required')] : $rules;
            }
        }

        return [
            'type' => ['required', Rule::enum(ChannelType::class)],
            'name' => ['required', 'string', 'max:100'],
            'config' => [$editing ? 'nullable' : 'required', 'array'],
            ...$configRules,
        ];
    }
}
