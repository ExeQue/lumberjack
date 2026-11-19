<?php

declare(strict_types=1);

namespace App\Domains\Ai\Presenters;

use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiBudgetTarget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Queries\AiBudgetQuery;
use App\Domains\Ai\Support\AiPriceBook;
use Illuminate\Database\Eloquent\Collection;

/**
 * Budgets as the pages show them: what each counts and how far it is in its period.
 */
class AiBudgetPresenter
{
    public function __construct(
        private readonly AiBudgetQuery $budgets,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(AiBudget $budget, ?AiPriceBook $book = null): array
    {
        return [
            'uuid' => $budget->uuid,
            'name' => $budget->name,
            'title' => $budget->title(),
            'scope' => $budget->scope->value,
            // A key budget's keys, with the ones the project forgot by their short id.
            'keys' => array_map(fn (string $fingerprint): array => ($key = $budget->keys()->get($fingerprint)) !== null
                ? ['value' => $key->uuid, 'short_id' => $key->shortId(), 'key' => AiUsagePresenter::key($key)]
                : ['value' => AiBudgetTarget::forgotten($fingerprint), 'short_id' => substr($fingerprint, 0, 8), 'key' => null], $budget->fingerprints()),
            // An owner or actor budget's owners or actors.
            'references' => $budget->references(),
            'period' => $budget->period->value,
            'basis' => $budget->basis->value,
            'payer' => $budget->payer->value,
            'amount' => $budget->amount,
            'usage' => $this->budgets->measure($budget, book: $book)->toArray(),
        ];
    }

    /**
     * @param  Collection<int, AiBudget>  $budgets  of one project
     * @return list<array<string, mixed>>
     */
    public function presentAll(Collection $budgets, ?AiPriceBook $book = null): array
    {
        $presented = [];
        $budgets->loadMissing('targets');
        $first = $budgets->first();
        // The key budgets' keys in one query.
        $fingerprints = $budgets->flatMap(fn (AiBudget $budget): array => $budget->fingerprints())->unique()->values()->all();
        $keys = $first !== null && $fingerprints !== []
            ? AiKey::query()->whereProject($first->project_id)->whereIn('fingerprint', $fingerprints)->get()
            : new Collection();

        foreach ($budgets as $budget) {
            $budget->usingKeys($keys);
            $book ??= AiPriceBook::for($budget->project);
            $presented[] = $this->present($budget, $book);
        }

        return $presented;
    }
}
