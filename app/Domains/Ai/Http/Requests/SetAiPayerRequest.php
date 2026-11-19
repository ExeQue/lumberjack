<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Requests;

use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Who pays for a key, or the project's default. A key may be set back to inherit (empty);
 * the project's default may not.
 */
class SetAiPayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor($this->route('aiKey') !== null);
    }

    /**
     * The rules for a key's payer, or the project's default; shared with the MCP tools.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(bool $forKey): array
    {
        return [
            'payer' => [$forKey ? 'nullable' : 'required', Rule::enum(AiPayer::class)],
        ];
    }

    public function payer(): ?AiPayer
    {
        return AiPayer::tryFrom($this->string('payer')->toString());
    }
}
