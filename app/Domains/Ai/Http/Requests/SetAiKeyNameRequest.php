<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A key's name in Lumberjack; empty takes it away.
 */
class SetAiKeyNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * Shared with the MCP tools.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function name(): ?string
    {
        return $this->filled('name') ? $this->string('name')->toString() : null;
    }
}
