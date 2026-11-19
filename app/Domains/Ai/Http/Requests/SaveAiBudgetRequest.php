<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Requests;

use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetPeriod;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiBudgetTarget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding or changing a budget: what it counts (the project, a key, a key owner or an
 * actor), per day or month, in the team's currency or in tokens, and how much.
 */
class SaveAiBudgetRequest extends FormRequest
{
    /** decimal(18,6) holds twelve digits before the point. */
    public const int MAX_AMOUNT = 1_000_000_000_000;

    /** Keys, key owners or actors one budget may count, at most. */
    public const int MAX_TARGETS = 100;

    public function project(): Project
    {
        $project = $this->route('project');
        assert($project instanceof Project);

        return $project;
    }

    public function budget(): ?AiBudget
    {
        $budget = $this->route('aiBudget');

        return $budget instanceof AiBudget ? $budget : null;
    }

    /**
     * The keys that may be picked, as the form names them, with their fingerprints.
     *
     * @return array<string, string>
     */
    private function choices(): array
    {
        return once(fn (): array => self::choicesFor($this->project(), $this->budget(), $this->input('keys', [])));
    }

    /**
     * The keys that may be picked, as the form names them, with their fingerprints: the
     * project's keys by uuid (only those asked for), and the keys the budget counts that the
     * project forgot. Shared with the MCP tools.
     *
     * @return array<string, string>
     */
    public static function choicesFor(Project $project, ?AiBudget $budget, mixed $keys): array
    {
        $asked = array_values(array_filter((array) $keys, fn (mixed $value): bool => is_string($value) && Str::isUuid($value)));
        /** @var array<string, string> $known */
        $known = $asked === [] ? [] : AiKey::query()->whereProject($project)->whereIn('uuid', $asked)->pluck('fingerprint', 'uuid')->all();
        $kept = $budget?->fingerprints() ?? [];
        $remembered = $kept === [] ? [] : AiKey::query()->whereProject($project)->whereIn('fingerprint', $kept)->pluck('fingerprint')->all();
        $forgotten = [];

        foreach (array_diff($kept, $remembered) as $fingerprint) {
            $forgotten[AiBudgetTarget::forgotten($fingerprint)] = $fingerprint;
        }

        return [...$known, ...$forgotten];
    }

    /**
     * Before validation, so someone who may not change budgets learns nothing from it.
     */
    public function authorize(): bool
    {
        $budget = $this->route('aiBudget');

        return $budget instanceof AiBudget
            ? $this->user()?->can('update', $budget) ?? false
            : $this->user()?->can('update', $this->project()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor($this->choices(), $this->input('basis'));
    }

    /**
     * The rules for a budget whose keys may be picked from $choices (see choicesFor());
     * shared with the MCP tools.
     *
     * @param  array<string, string>  $choices
     * @return array<string, mixed>
     */
    public static function rulesFor(array $choices, mixed $basis): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'scope' => ['required', Rule::enum(AiBudgetScope::class)],
            // Keys of this project by uuid, and ones the budget counts that the project forgot.
            'keys' => ['exclude_unless:scope,key', 'required', 'array', 'min:1', 'max:'.self::MAX_TARGETS],
            'keys.*' => ['bail', 'string', 'distinct', Rule::in(array_keys($choices))],
            // Key owners or actors, each a type and an id, as calls name them.
            ...self::referenceRules('owners', AiBudgetScope::Owner),
            ...self::referenceRules('actors', AiBudgetScope::Actor),
            'period' => ['required', Rule::enum(AiBudgetPeriod::class)],
            'basis' => ['required', Rule::enum(AiBudgetBasis::class)],
            // Empty: everyone for an owner or actor, us for the project or a key (or as it was, when changed).
            'payer' => ['nullable', Rule::enum(AiBudgetPayer::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_AMOUNT, ...($basis === AiBudgetBasis::Tokens->value ? ['integer'] : [])],
        ];
    }

    /** @return array<string, mixed> */
    private static function referenceRules(string $field, AiBudgetScope $scope): array
    {
        return [
            $field => ['exclude_unless:scope,'.$scope->value, 'required', 'array', 'min:1', 'max:'.self::MAX_TARGETS],
            $field.'.*' => ['array:type,id'],
            $field.'.*.type' => ['required', 'string', 'max:128'],
            $field.'.*.id' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * The same owner or actor twice is one too many. Only the list the scope uses, and only
     * entries the rules took: the rules tell what is wrong with the others.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [self::duplicatesFor($this->all())];
    }

    /**
     * The same owner or actor twice in $input; shared with the MCP tools.
     *
     * @param  array<string, mixed>  $input
     * @return Closure(Validator): void
     */
    public static function duplicatesFor(array $input): Closure
    {
        return function (Validator $validator) use ($input): void {
            $field = match ($input['scope'] ?? null) {
                AiBudgetScope::Owner->value => 'owners',
                AiBudgetScope::Actor->value => 'actors',
                default => null,
            };
            $seen = [];

            foreach ($field !== null ? (array) ($input[$field] ?? []) : [] as $index => $reference) {
                $type = is_array($reference) ? $reference['type'] ?? null : null;
                $id = is_array($reference) ? $reference['id'] ?? null : null;

                if (! is_string($type) || ! is_string($id)) {
                    continue;
                }

                $pair = json_encode([$type, $id], JSON_THROW_ON_ERROR);

                if (isset($seen[$pair])) {
                    $validator->errors()->add($field.'.'.$index, (string) __('validation.distinct', ['attribute' => $field]));
                }

                $seen[$pair] = true;
            }
        };
    }

    /**
     * @return array{name: string|null, scope: AiBudgetScope, targets: list<array{fingerprint: string|null, ref_type: string|null, ref_id: string|null}>, period: AiBudgetPeriod, basis: AiBudgetBasis, payer: AiBudgetPayer|null, amount: float}
     */
    public function values(): array
    {
        return self::valuesFrom($this->validated(), $this->choices());
    }

    /**
     * What the action takes, from validated input; shared with the MCP tools.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, string>  $choices
     * @return array{name: string|null, scope: AiBudgetScope, targets: list<array{fingerprint: string|null, ref_type: string|null, ref_id: string|null}>, period: AiBudgetPeriod, basis: AiBudgetBasis, payer: AiBudgetPayer|null, amount: float}
     */
    public static function valuesFrom(array $validated, array $choices): array
    {
        $scope = AiBudgetScope::from((string) $validated['scope']);

        return [
            'name' => filled($validated['name'] ?? null) ? (string) $validated['name'] : null,
            'scope' => $scope,
            'targets' => match ($scope) {
                AiBudgetScope::Project => [],
                AiBudgetScope::Key => array_values(array_map(
                    fn (string $value): array => ['fingerprint' => $choices[$value], 'ref_type' => null, 'ref_id' => null],
                    (array) $validated['keys'],
                )),
                AiBudgetScope::Owner, AiBudgetScope::Actor => array_values(array_map(
                    fn (array $reference): array => ['fingerprint' => null, 'ref_type' => (string) $reference['type'], 'ref_id' => (string) $reference['id']],
                    (array) $validated[$scope === AiBudgetScope::Owner ? 'owners' : 'actors'],
                )),
            },
            'period' => AiBudgetPeriod::from((string) $validated['period']),
            'basis' => AiBudgetBasis::from((string) $validated['basis']),
            'payer' => filled($validated['payer'] ?? null) ? AiBudgetPayer::from((string) $validated['payer']) : null,
            'amount' => (float) $validated['amount'],
        ];
    }
}
