<?php

declare(strict_types=1);

use App\Domains\Shared\Http\Controllers\TranslationsController;
use App\Domains\Shared\Support\Translations;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(TranslationsController::class, Translations::class);

describe('the UI strings', function () {
    it('serves a locale\'s strings for good, at an address carrying their version', function () {
        $url = Translations::url('en');

        expect($url)->toStartWith('/i18n/en.json?v=');

        $response = $this->get($url)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('immutable')
            ->and($response->json('common.nav.projects'))->toBe('Projects');
    });

    it('is not redirected by setup or onboarding, as the pages need it to show any text', function () {
        $this->actingAs(User::factory()->inTeam()->notOnboarded()->create())
            ->get(Translations::url('en'))
            ->assertOk()
            ->assertJsonPath('common.nav.projects', 'Projects');
    });

    it('answers 404 for a locale it has no strings for', function () {
        $this->get('/i18n/xx.json')->assertNotFound();
    });

    it('gives pages where the strings are, not the strings themselves', function () {
        $this->actingAs(User::factory()->inTeam()->create())->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations_url', Translations::url('en'))
                ->missing('translations'));
    });
});
