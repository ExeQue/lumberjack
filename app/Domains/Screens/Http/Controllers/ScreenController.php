<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\CountScreenViewersAction;
use App\Domains\Screens\Actions\CreateScreenAction;
use App\Domains\Screens\Actions\DeleteScreenAction;
use App\Domains\Screens\Actions\DuplicateScreenAction;
use App\Domains\Screens\Actions\ResolveScreenDataAction;
use App\Domains\Screens\Actions\UpdateScreenAction;
use App\Domains\Screens\Actions\UpdateScreenLayoutAction;
use App\Domains\Screens\Actions\UpdateScreenWidgetAction;
use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Enums\ScreenAspect;
use App\Domains\Screens\Enums\ScreenTemplate;
use App\Domains\Screens\Exceptions\WidgetNotFoundException;
use App\Domains\Screens\Http\Requests\ScreenDataRequest;
use App\Domains\Screens\Http\Requests\StoreScreenRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenLayoutRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenWidgetRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Presenters\ScreenPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ScreenController
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_if($team === null, 404);

        return Inertia::render('screens/index', [
            'screens' => $team->screens()->orderBy('name')->get()->map(fn (Screen $s): array => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'cols' => $s->cols,
                'rows' => $s->rows,
                'theme' => $s->theme->value,
                'widgets' => count($s->widgets),
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])->all(),
            'templates' => array_map(fn (ScreenTemplate $t): array => $t->toArray(), ScreenTemplate::cases()),
        ]);
    }

    public function store(StoreScreenRequest $request, CreateScreenAction $action): RedirectResponse
    {
        $screen = $action->execute($request->user()->currentTeam, $request->string('name')->toString(), $request->template());

        return redirect()->route('screens.edit', $screen);
    }

    /** The kiosk: the screen full screen, without the app around it. */
    public function show(Screen $screen, ScreenPresenter $presenter): Response
    {
        Gate::authorize('view', $screen);

        // Where this kiosk reads its data and hears about changes; a paired display and a
        // share link get their own.
        return Inertia::render('screens/kiosk', $presenter->kiosk($screen, [
            'data_url' => route('screens.data', $screen, absolute: false),
            'channel' => ScreenChannel::name($screen),
        ]));
    }

    public function edit(Request $request, Screen $screen, ScreenPresenter $presenter): Response
    {
        Gate::authorize('update', $screen);

        return Inertia::render('screens/edit', [
            'sharing' => $presenter->sharing($screen, $request->user()),
            'tv' => $presenter->tvInstructions(),
            'screen' => $presenter->present($screen),
            'team' => ['name' => $screen->team->name],
            'displays' => $presenter->previewDisplays($screen),
            'projects' => $presenter->projects($screen->team),
            'data_url' => route('screens.data', $screen, absolute: false),
            'templates' => ScreenTemplate::forDesigner(),
            'aspects' => array_map(fn (ScreenAspect $a): string => $a->value, ScreenAspect::cases()),
            ...$presenter->designer($screen->team),
        ]);
    }

    public function update(UpdateScreenRequest $request, Screen $screen, UpdateScreenAction $action): RedirectResponse|JsonResponse
    {
        $action->execute($screen, $request->settings());

        return $request->wantsJson() ? $this->saved($screen) : back();
    }

    public function layout(UpdateScreenLayoutRequest $request, Screen $screen, UpdateScreenLayoutAction $action): JsonResponse
    {
        $action->execute($screen, $request->integer('cols'), $request->integer('rows'), array_values((array) $request->input('widgets', [])));

        return $this->saved($screen);
    }

    public function widget(UpdateScreenWidgetRequest $request, Screen $screen, string $widget, UpdateScreenWidgetAction $action): JsonResponse
    {
        try {
            $action->execute($screen, $widget, (array) $request->input('widget'));
        } catch (WidgetNotFoundException) {
            abort(404);
        }

        return $this->saved($screen);
    }

    public function duplicate(Screen $screen, DuplicateScreenAction $action): RedirectResponse
    {
        Gate::authorize('update', $screen);
        $action->execute($screen);

        return back()->with('status', __('screens.duplicated'));
    }

    public function destroy(Screen $screen, DeleteScreenAction $action): RedirectResponse
    {
        Gate::authorize('delete', $screen);
        $action->execute($screen);

        return redirect()->route('screens.index')->with('status', __('screens.deleted'));
    }

    /** Every widget's data, or one widget's (?widget=id) after its settings changed. */
    public function data(ScreenDataRequest $request, Screen $screen, ResolveScreenDataAction $action): JsonResponse
    {
        return response()->json([
            'data' => (object) $action->execute($screen, $request->filled('widget') ? $request->string('widget')->toString() : null),
            'at' => now()->toIso8601String(),
        ]);
    }

    /** How many kiosks show the screen now, for "live on N screens" in the designer. */
    public function viewers(Screen $screen, CountScreenViewersAction $action): JsonResponse
    {
        Gate::authorize('view', $screen);

        return response()->json(['count' => $action->execute($screen)]);
    }

    private function saved(Screen $screen): JsonResponse
    {
        return response()->json(['updated_at' => $screen->updated_at?->toIso8601String()]);
    }
}
