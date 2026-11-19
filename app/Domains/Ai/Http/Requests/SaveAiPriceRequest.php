<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Requests;

use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or changing a price: what it applies to, for which models, from when, and per
 * million tokens what input, output, cache reads and writes and reasoning cost.
 */
class SaveAiPriceRequest extends FormRequest
{
    /** Per million tokens, at most: decimal(18,6) holds twelve digits before the point. */
    public const int MAX_PRICE = 1_000_000;

    public function project(): Project
    {
        $project = $this->route('project');
        assert($project instanceof Project);

        return $project;
    }

    /**
     * Before validation, so someone who may not change prices learns nothing from it.
     */
    public function authorize(): bool
    {
        $price = $this->route('aiPrice');

        return $price instanceof AiPrice
            ? $this->user()?->can('update', [$price, $this->project()]) ?? false
            : $this->user()?->can('update', $this->project()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor($this->project());
    }

    /**
     * The rules for a price in $project; shared with the MCP tools.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(Project $project): array
    {
        $price = ['numeric', 'min:0', 'max:'.self::MAX_PRICE];

        return [
            'scope' => ['required', Rule::enum(AiPriceScope::class)],
            'team_wide' => ['boolean'],
            'owner_type' => ['exclude_unless:scope,owner', 'required', 'string', 'max:128'],
            'owner_id' => ['exclude_unless:scope,owner', 'required', 'string', 'max:64'],
            // A key of this project, by its uuid.
            'key' => ['exclude_unless:scope,key', 'bail', 'required', 'uuid', Rule::exists('ai_keys', 'uuid')->where('project_id', $project->id)],
            'provider' => ['nullable', 'string', 'max:64', 'regex:/^\S+$/'],
            // A model name, or a glob with *.
            'model_pattern' => ['required', 'string', 'max:128', 'regex:/^\S+$/'],
            'valid_from' => ['required', 'date'],
            'input' => ['required', ...$price],
            'output' => ['required', ...$price],
            'cache_read' => ['nullable', ...$price],
            'cache_write' => ['nullable', ...$price],
            'reasoning' => ['nullable', ...$price],
        ];
    }

    public function scope(): AiPriceScope
    {
        return AiPriceScope::from($this->string('scope')->toString());
    }

    /**
     * @return array{team_wide: bool, scope_type: string|null, scope_id: string|null, provider: string|null, model_pattern: string, valid_from: CarbonImmutable, input: float, output: float, cache_read: float|null, cache_write: float|null, reasoning: float|null}
     */
    public function values(): array
    {
        return self::valuesFrom($this->project(), $this->validated());
    }

    /**
     * What the action takes, from validated input; shared with the MCP tools.
     *
     * @param  array<string, mixed>  $validated
     * @return array{team_wide: bool, scope_type: string|null, scope_id: string|null, provider: string|null, model_pattern: string, valid_from: CarbonImmutable, input: float, output: float, cache_read: float|null, cache_write: float|null, reasoning: float|null}
     */
    public static function valuesFrom(Project $project, array $validated): array
    {
        $scope = AiPriceScope::from((string) $validated['scope']);
        $optional = fn (string $key): ?float => filled($validated[$key] ?? null) ? (float) $validated[$key] : null;

        return [
            'team_wide' => filter_var($validated['team_wide'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'scope_type' => $scope === AiPriceScope::Owner ? (string) $validated['owner_type'] : null,
            'scope_id' => match ($scope) {
                AiPriceScope::Owner => (string) $validated['owner_id'],
                AiPriceScope::Key => AiKey::query()->whereProject($project)->whereUuid((string) $validated['key'])->value('fingerprint'),
                AiPriceScope::Project => null,
            },
            'provider' => filled($validated['provider'] ?? null) ? (string) $validated['provider'] : null,
            'model_pattern' => (string) $validated['model_pattern'],
            'valid_from' => CarbonImmutable::parse((string) $validated['valid_from'])->utc(),
            'input' => (float) $validated['input'],
            'output' => (float) $validated['output'],
            'cache_read' => $optional('cache_read'),
            'cache_write' => $optional('cache_write'),
            'reasoning' => $optional('reasoning'),
        ];
    }
}
