<?php

declare(strict_types=1);

namespace App\Domains\Logs\Http\Requests;

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLogChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return self::rulesFor($project);
    }

    /**
     * Also used by MCP's create_log_channel.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(Project $project): array
    {
        return [
            'name' => [
                'required', 'string', 'max:64', 'regex:'.LogChannel::NAME_PATTERN, Rule::notIn([LogChannel::DEFAULT]),
                Rule::unique('log_channels', 'name')->where('project_id', $project->id),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.regex' => __('logs.validation.kebab')];
    }
}
