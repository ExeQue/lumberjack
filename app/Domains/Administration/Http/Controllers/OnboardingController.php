<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Actions\CompleteOnboardingAction;
use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Notifications\Support\PushSettings;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first-login onboarding: where things are, how to be notified, and browser
 * notifications on this device. Each step is its own visit, so saving the notification
 * settings or turning on this browser comes back to the step it was made on. See
 * docs/teams.md.
 */
class OnboardingController
{
    public const int STEPS = 3;

    public function show(Request $request, int $step = 1): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('onboarding/show', [
            'step' => $step,
            'steps' => self::STEPS,
            ...match ($step) {
                // The user's own memberships: an administrator's visited team is not theirs.
                1 => ['teams' => $user->teams()->with(['projects' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get()
                    ->map(fn (Team $team): array => [
                        'uuid' => $team->uuid,
                        'name' => $team->name,
                        'projects' => $team->projects->pluck('name')->all(),
                    ])->all()],
                2 => [
                    'notifications' => $user->notification_preferences->toArray(),
                    'presets' => NotificationPreferences::presets(),
                ],
                default => ['push' => PushSettings::for($user)],
            },
        ]);
    }

    /**
     * Done or Skip: not shown again, and on to where the user was going.
     */
    public function complete(Request $request, CompleteOnboardingAction $completeOnboarding): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $completeOnboarding->execute($user);

        return redirect()->intended(route('projects.index'));
    }
}
