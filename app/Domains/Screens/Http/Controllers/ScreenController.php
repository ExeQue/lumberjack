<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\CountScreenViewersAction;
use App\Domains\Screens\Actions\CreateScreenAction;
use App\Domains\Screens\Actions\DeleteScreenAction;
use App\Domains\Screens\Actions\DuplicateScreenAction;
use App\Domains\Screens\Actions\ImportScreenAction;
use App\Domains\Screens\Actions\RequestReloadAction;
use App\Domains\Screens\Actions\ResolveScreenDataAction;
use App\Domains\Screens\Actions\UpdateScreenAction;
use App\Domains\Screens\Actions\UpdateScreenLayoutAction;
use App\Domains\Screens\Actions\UpdateScreenWidgetAction;
use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Broadcasting\TeamDisplaysChannel;
use App\Domains\Screens\Enums\ScreenAspect;
use App\Domains\Screens\Enums\ScreenTemplate;
use App\Domains\Screens\Exceptions\ReloadTooSoonException;
use App\Domains\Screens\Exceptions\WidgetNotFoundException;
use App\Domains\Screens\Http\Requests\ScreenDataRequest;
use App\Domains\Screens\Http\Requests\StoreScreenRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenLayoutRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenWidgetRequest;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\SavedScreenTemplate;
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
    public function index(Request $request, CountScreenViewersAction $viewers): Response
    {
        $team = $request->user()->currentTeam;
        abort_if($team === null, 404);

        $screens = $team->screens()->orderBy('name')->get();
        // The paired TVs set to show each screen, alone or in their turn.
        $displays = Display::query()->whereBelongsTo($team)->get(['screen_id', 'playlist']);
        $pairedTo = fn (Screen $s): int => $displays->filter(fn (Display $d): bool => $d->screen_id === $s->id || in_array($s->id, $d->playlist ?? [], true))->count();

        return Inertia::render('screens/index', [
            'screens' => $screens->map(fn (Screen $s): array => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'cols' => $s->cols,
                'rows' => $s->rows,
                'theme' => $s->theme->value,
                'widgets' => count($s->widgets),
                'updated_at' => $s->updated_at?->toIso8601String(),
                'shared' => $s->share_key_hash !== null,
                'displays' => $pairedTo($s),
            ])->all(),
            // Asked from Reverb per screen, so after the page; null where it cannot tell.
            'viewers' => Inertia::defer(fn (): array => $screens->mapWithKeys(fn (Screen $s): array => [$s->uuid => $viewers->execute($s)])->all()),
            'templates' => array_map(fn (ScreenTemplate $t): array => $t->toArray(), ScreenTemplate::cases()),
            'saved_templates' => SavedScreenTemplate::query()->whereBelongsTo($team)->with('creator')->orderBy('name')->get()
                ->map(fn (SavedScreenTemplate $t): array => $t->toPicker())->all(),
            'can_manage_templates' => $request->user()->canAdministerTeam($team),
        ]);
    }

    /** From a built-in template, or one the team saved (made the way an import is). */
    public function store(StoreScreenRequest $request, CreateScreenAction $action, ImportScreenAction $import): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $name = $request->string('name')->toString();
        $saved = $request->savedTemplate();

        if ($saved === null) {
            return redirect()->route('screens.edit', $action->execute($team, $name, $request->template()));
        }

        Gate::authorize('use', $saved);
        $result = $import->execute($team, $saved->definition, $name);

        return redirect()->route('screens.edit', $result->screen)->with('status', $result->hasDropped() ? $result->droppedNotice() : null);
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
            // Where a TV reporting a new size says so, to refresh the list above.
            'displays_channel' => TeamDisplaysChannel::name($screen->team),
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

    /** Everything showing the screen loads the page afresh; at most once every few seconds. */
    public function reload(Screen $screen, RequestReloadAction $action): JsonResponse
    {
        Gate::authorize('update', $screen);

        try {
            $count = $action->execute($screen);
        } catch (ReloadTooSoonException $e) {
            return response()->json(['message' => $e->getMessage()], 429, ['Retry-After' => (string) $e->retryAfter]);
        }

        return response()->json([
            'count' => $count,
            'message' => $count === null ? __('screens.reload.sent_unknown') : trans_choice('screens.reload.sent', $count, ['count' => $count]),
        ]);
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
