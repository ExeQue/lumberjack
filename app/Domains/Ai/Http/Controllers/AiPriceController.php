<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Actions\DeleteAiPriceAction;
use App\Domains\Ai\Actions\SaveAiPriceAction;
use App\Domains\Ai\Actions\SetProjectAiPayerAction;
use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Http\Requests\SaveAiPriceRequest;
use App\Domains\Ai\Http\Requests\SetAiPayerRequest;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Ai\Queries\AiTargetSearchQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The prices AI usage is charged at in a project (its own and its team's), and who pays
 * for a key that does not say.
 */
class AiPriceController
{
    /** Models offered to match a price against, at most. */
    public const int MODELS = 200;

    /** How far back a model counts as seen. */
    public const int MODELS_DAYS = 90;

    public function index(Request $request, Project $project, ProjectPresenter $presenter, AiTargetSearchQuery $targets): Response
    {
        $prices = AiPrice::query()->whereAppliesTo($project)->orderBy('model_pattern')->orderByDesc('valid_from')->get();
        // Only the keys the prices name; the form searches for the others as they are typed.
        $byFingerprint = $project->aiKeys()
            ->whereIn('fingerprint', $prices->where('scope', AiPriceScope::Key)->pluck('scope_id')->filter()->unique()->values()->all())
            ->get()->keyBy('fingerprint')->all();

        return Inertia::render('ai/prices', [
            'project' => $presenter->header($project, $request->user()),
            'prices' => $prices->map(fn (AiPrice $price): array => AiUsagePresenter::price($price, $byFingerprint))->all(),
            // What a price's pattern is matched against in the form, as it is typed: the models
            // used most lately, from the daily totals' (project, day, provider, model) index.
            'models' => DB::table('ai_usage_daily')->where('project_id', $project->id)
                ->where('day', '>=', now()->subDays(self::MODELS_DAYS)->toDateString())
                ->groupBy('provider', 'model')->orderByRaw('MAX(day) DESC')->orderBy('model')
                ->limit(self::MODELS)->get(['provider', 'model'])
                ->map(fn (object $row): array => ['provider' => (string) $row->provider, 'model' => (string) $row->model])->all(),
            // Suggested as typed; capped like the budgets' search, any other owner can still be typed in full.
            'owners' => $targets->owners($project, ''),
            'default_payer' => $project->ai_default_payer->value,
            'can_update' => (bool) $request->user()?->can('update', $project),
        ]);
    }

    public function store(SaveAiPriceRequest $request, Project $project, SaveAiPriceAction $action): RedirectResponse
    {
        $action->execute($project, null, $request->scope(), $request->values());

        return back()->with('status', __('ui/ai.prices.saved'));
    }

    public function update(SaveAiPriceRequest $request, Project $project, AiPrice $aiPrice, SaveAiPriceAction $action): RedirectResponse
    {
        $action->execute($project, $aiPrice, $request->scope(), $request->values());

        return back()->with('status', __('ui/ai.prices.saved'));
    }

    public function destroy(Project $project, AiPrice $aiPrice, DeleteAiPriceAction $action): RedirectResponse
    {
        Gate::authorize('delete', [$aiPrice, $project]);

        $action->execute($aiPrice);

        return back()->with('status', __('ui/ai.prices.deleted'));
    }

    public function payer(SetAiPayerRequest $request, Project $project, SetProjectAiPayerAction $action): RedirectResponse
    {
        $payer = $request->payer();
        assert($payer !== null);
        $action->execute($project, $payer);

        return back()->with('status', __('ui/ai.prices.payer_saved'));
    }
}
