<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Actions\SetAiKeyNameAction;
use App\Domains\Ai\Actions\SetAiKeyPayerAction;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Http\Requests\AiUsageFiltersRequest;
use App\Domains\Ai\Http\Requests\LookUpAiKeyRequest;
use App\Domains\Ai\Http\Requests\SetAiKeyNameRequest;
use App\Domains\Ai\Http\Requests\SetAiPayerRequest;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Presenters\AiBudgetPresenter;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Ai\Queries\AiBudgetQuery;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Ai\Support\AiKeyFingerprint;
use App\Domains\Ai\Support\AiPriceBook;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One AI key: its usage and cost, the prices it is charged at, who pays for it and its
 * latest calls; and finding a key by the hash of the key itself.
 */
class AiKeyController
{
    use ReadsDashboardFilters;

    public const int PER_PAGE = 25;

    public function show(AiUsageFiltersRequest $request, Project $project, AiKey $aiKey, AiUsagePresenter $usage, ProjectPresenter $presenter, AiBudgetQuery $budgets, AiBudgetPresenter $budgetPresenter): Response
    {
        $period = $this->period($request, AiUsageController::DEFAULT_PERIOD);
        $environment = $this->environment($request);
        $only = ['fingerprint' => $aiKey->fingerprint];
        $book = AiPriceBook::for($project);
        $now = now()->toImmutable();
        [$from] = $usage->window($period, AiCostQuery::HOURLY, $now);

        $calls = AiCall::query()
            ->where('project_id', $project->id)
            ->whereFingerprint($aiKey->fingerprint)
            ->when($environment !== null)->where('environment', $environment)
            ->when($request->onlyErrors())->whereFailed()
            // The id breaks ties of a millisecond, so the cursor never skips or repeats one.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('ai/key', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request, AiUsageController::DEFAULT_PERIOD), 'errors' => $request->onlyErrors()],
            // Not "key", which React keeps to itself.
            'ai_key' => [
                ...AiUsagePresenter::key($aiKey),
                'first_seen_at' => $aiKey->first_seen_at->toIso8601String(),
                'last_seen_at' => $aiKey->last_seen_at->toIso8601String(),
                'effective_payer' => $aiKey->effectivePayer($project->ai_default_payer)->value,
            ],
            'default_payer' => $project->ai_default_payer->value,
            'summary' => Inertia::defer(fn (): array => $usage->summary($project, $period, $environment, AiCostQuery::HOURLY, $only, $book, $now)),
            'chart' => Inertia::defer(fn (): array => $usage->chart($project, $period, $environment, AiCostQuery::HOURLY, $only, $book, $now)),
            'prices' => $usage->keyPrices($project, $aiKey, $from, $book, $environment),
            'budgets' => Inertia::defer(fn (): array => $budgetPresenter->presentAll($budgets->covering($project, AiBudgetScope::Key, null, $aiKey->fingerprint), $book)),
            'calls' => array_map(AiUsagePresenter::call(...), $calls->items()),
            'next_url' => $calls->nextPageUrl(),
            'prev_url' => $calls->previousPageUrl(),
            'can_update' => (bool) $request->user()?->can('update', $project),
        ]);
    }

    public function payer(SetAiPayerRequest $request, Project $project, AiKey $aiKey, SetAiKeyPayerAction $action): RedirectResponse
    {
        $action->execute($aiKey, $request->payer());

        return back()->with('status', __('ui/ai.key.payer_saved'));
    }

    public function name(SetAiKeyNameRequest $request, Project $project, AiKey $aiKey, SetAiKeyNameAction $action): RedirectResponse
    {
        $action->execute($aiKey, $request->name());

        return back()->with('status', __('ui/ai.key.name_saved'));
    }

    /**
     * Opens the key whose hash the browser made from the key pasted in, one hash per driver
     * it could be for: the key never reaches the server, only those hashes, which are
     * fingerprinted as at ingest and then forgotten.
     */
    public function lookup(LookUpAiKeyRequest $request, Project $project): RedirectResponse
    {
        /** @var list<string> $hashes */
        $hashes = $request->validated('hashes');
        $fingerprints = array_map(fn (string $hash): string => (string) AiKeyFingerprint::of($project->ai_key_secret, $hash), $hashes);
        $key = $project->aiKeys()->whereIn('fingerprint', $fingerprints)->latest('last_seen_at')->first();

        if ($key === null) {
            return back()->withErrors(['hashes' => __('ui/ai.lookup.not_found')]);
        }

        return redirect()->route('ai.keys.show', ['project' => $project, 'aiKey' => $key->uuid]);
    }
}
