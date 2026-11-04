<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Performance\Enums\DeviceClass;
use App\Domains\Performance\Http\Controllers\BrowserController;
use App\Domains\Performance\Models\WebVital;
use App\Domains\Performance\Queries\BrowserQuery;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(BrowserController::class, BrowserQuery::class);

beforeEach(function () {
    $this->travelTo(now()->startOfHour()->addMinutes(30));
    $this->project = Project::factory()->create();
    $this->user = User::factory()->inTeam($this->project->team)->create();
});

describe('the browser page', function () {
    it('shows the p75 of each vital, the share rated good and the period before', function () {
        foreach ([1_000_000, 2_000_000, 3_000_000, 9_000_000] as $lcp) {
            WebVital::factory()->for($this->project)->create(['lcp_us' => $lcp]);
        }

        WebVital::factory()->for($this->project)->poor()->create(['occurred_at' => now()->subHours(30)]);
        WebVital::factory()->for($this->project)->create(['occurred_at' => now()->subDays(3)]);

        $this->actingAs($this->user)
            ->get(route('browser.index', ['project' => $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('browser/index')
                ->where('has_vitals', true)
                ->where('summary.views', 4)
                ->where('summary.lcp_us', 3_000_000)
                ->where('summary.good.lcp', 0.5)
                ->where('summary.good.cls', 1)
                ->where('summary.previous.views', 1)
                ->where('summary.previous.lcp_us', 5_000_000)
                ->where('series', fn ($series) => collect($series)->last()['lcp_us'] === 3_000_000 && collect($series)->first()['lcp_us'] === null));
    });

    it('narrows every figure to a device, and splits LCP per device', function () {
        WebVital::factory()->for($this->project)->onDevice(DeviceClass::Mobile)->poor()->forRoute('/checkout')->create();
        WebVital::factory()->for($this->project)->onDevice(DeviceClass::Desktop)->forRoute('/')->create();
        WebVital::factory()->for($this->project)->withoutDevice()->forRoute('/')->create();

        $this->actingAs($this->user)
            ->get(route('browser.index', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.views', 3)
                ->where('devices', [
                    ['device' => 'mobile', 'views' => 1, 'lcp_us' => 5_000_000],
                    ['device' => 'tablet', 'views' => 0, 'lcp_us' => null],
                    ['device' => 'desktop', 'views' => 1, 'lcp_us' => 1_200_000],
                ]));

        $this->actingAs($this->user)
            ->get(route('browser.index', ['project' => $this->project, 'device' => 'mobile']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.device', 'mobile')
                ->where('summary.views', 1)
                ->where('summary.lcp_us', 5_000_000)
                ->has('pages', 1)
                ->where('pages.0.route', '/checkout'));
    });

    it('lists pages with every vital, the most viewed or the slowest LCP first', function () {
        WebVital::factory()->for($this->project)->forRoute('/')->count(2)->create();
        WebVital::factory()->for($this->project)->forRoute('/search')->poor()->create();

        $this->actingAs($this->user)
            ->get(route('browser.index', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'views')
                ->where('pages.0', ['route' => '/', 'views' => 2, 'lcp_us' => 1_200_000, 'inp_us' => 80_000, 'cls' => 0.02, 'ttfb_us' => 150_000, 'fcp_us' => 700_000])
                ->where('pages.1.route', '/search'));

        $this->actingAs($this->user)
            ->get(route('browser.index', ['project' => $this->project, 'sort' => 'lcp']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pages.0.route', '/search')
                ->where('pages.0.lcp_us', 5_000_000));
    });

    it('counts browser errors and lists the issues with the most', function () {
        $often = Issue::factory()->for($this->project)->fromBrowser()->create();
        $once = Issue::factory()->for($this->project)->fromBrowser()->create();
        $server = Issue::factory()->for($this->project)->create();
        Occurrence::factory()->for($often)->count(3)->create();
        Occurrence::factory()->for($once)->create();
        Occurrence::factory()->for($server)->count(5)->create();

        $this->actingAs($this->user)
            ->get(route('browser.index', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('errors.occurrences', 4)
                ->has('errors.issues', 2)
                ->where('errors.issues.0.id', $often->uuid)
                ->where('errors.issues.0.occurrences', 3)
                ->where('errors.issues.1.id', $once->uuid));
    });

    it('tells a project without page views to set up the browser script', function () {
        WebVital::factory()->create();

        $this->actingAs($this->user)
            ->get(route('browser.index', ['project' => $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('has_vitals', false)
                ->where('summary.views', 0)
                ->where('summary.lcp_us', null)
                ->where('summary.good.lcp', null)
                ->has('pages', 0)
                ->where('errors.occurrences', 0));
    });
});

describe('who may see the browser page', function () {
    it('turns away someone outside the project\'s team', function () {
        $this->actingAs(User::factory()->inTeam()->create())
            ->get(route('browser.index', ['project' => $this->project]))
            ->assertForbidden();
    });

    it('sends a guest to log in', function () {
        $this->get(route('browser.index', ['project' => $this->project]))
            ->assertRedirect(route('login'));
    });
});
