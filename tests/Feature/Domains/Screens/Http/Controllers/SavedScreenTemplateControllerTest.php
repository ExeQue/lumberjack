<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Screens\Actions\SaveScreenTemplateAction;
use App\Domains\Screens\Http\Controllers\SavedScreenTemplateController;
use App\Domains\Screens\Http\Controllers\ScreenController;
use App\Domains\Screens\Http\Requests\SaveScreenTemplateRequest;
use App\Domains\Screens\Http\Requests\StoreScreenRequest;
use App\Domains\Screens\Models\SavedScreenTemplate;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Policies\SavedScreenTemplatePolicy;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Teams\CreatesTeamMembers;

covers(
    SavedScreenTemplateController::class,
    SaveScreenTemplateAction::class,
    SaveScreenTemplateRequest::class,
    SavedScreenTemplate::class,
    SavedScreenTemplatePolicy::class,
    ScreenController::class,
    StoreScreenRequest::class,
);

uses(CreatesTeamMembers::class);

beforeEach(function () {
    ['user' => $this->user, 'project' => $this->project] = $this->memberWithProject(TeamRole::Member);
    $this->team = $this->project->team;
    $this->admin = User::factory()->inTeam($this->team, TeamRole::Admin)->create();
    $this->check = HealthCheck::factory()->for($this->project)->reported('queue')->create();
    $this->screen = Screen::factory()->for($this->team)->scopedTo($this->project)->withHealthWidget($this->check)->create(['name' => 'Ops']);
});

describe('saving a screen as a template', function () {
    it('stores its portable file for the team, by any member', function () {
        $this->actingAs($this->user)->from(route('screens.index'))
            ->post(route('screens.template', $this->screen), ['name' => ' Wall ', 'description' => 'By the coffee machine'])
            ->assertRedirect(route('screens.index'))
            ->assertSessionHas('status', 'Saved as the template Wall; New screen offers it.');

        $template = SavedScreenTemplate::query()->sole();

        expect($template->team_id)->toBe($this->team->id)
            ->and($template->name)->toBe('Wall')
            ->and($template->description)->toBe('By the coffee machine')
            ->and($template->created_by)->toBe($this->user->id)
            ->and($template->definition['format'])->toBe('lumberjack-screen')
            ->and($template->definition['screen']['scope']['projects'])->toBe([$this->project->slug]);
    });

    it('needs a name', function () {
        $this->actingAs($this->user)->post(route('screens.template', $this->screen), ['name' => ''])->assertSessionHasErrors('name');
    });

    it('is only for the screen\'s team', function () {
        $stranger = User::factory()->inTeam()->create();

        $this->actingAs($stranger)->post(route('screens.template', $this->screen), ['name' => 'Wall'])->assertForbidden();
    });
});

describe('the team\'s templates on the list of screens', function () {
    it('are offered with their grid and widgets for the thumbnail', function () {
        $template = SavedScreenTemplate::factory()->from($this->screen)->create(['created_by' => $this->user->id]);
        SavedScreenTemplate::factory()->create();

        $this->actingAs($this->user)->get(route('screens.index'))->assertInertia(fn (Assert $page) => $page
            ->has('saved_templates', 1)
            ->where('saved_templates.0.uuid', $template->uuid)
            ->where('saved_templates.0.label', 'Ops')
            ->where('saved_templates.0.cols', 4)
            ->where('saved_templates.0.creator', $this->user->name)
            ->where('saved_templates.0.widgets.3', ['type' => 'health', 'x' => 0, 'y' => 3, 'w' => 2, 'h' => 1])
            ->where('can_manage_templates', false));
    });

    it('make a new screen the way an import does', function () {
        $template = SavedScreenTemplate::factory()->from($this->screen)->create();
        $this->screen->delete();

        $this->actingAs($this->user)->post(route('screens.store'), ['name' => 'Second wall', 'saved_template' => $template->uuid])
            ->assertSessionHasNoErrors();

        $screen = $this->team->screens()->sole();

        expect($screen->name)->toBe('Second wall')
            ->and($screen->scope['projects'])->toBe([$this->project->uuid])
            ->and($screen->widgets[3]['options']['checks'])->toBe([$this->check->uuid]);
    });

    it('say what they name that the team no longer has', function () {
        $template = SavedScreenTemplate::factory()->from($this->screen)->create();
        $this->check->delete();

        $this->actingAs($this->user)->post(route('screens.store'), ['name' => 'Second wall', 'saved_template' => $template->uuid])
            ->assertSessionHas('status', 'Not found in this team, so left out: check '.$this->project->slug.': queue.');
    });

    it('are not found from another team', function () {
        $foreign = SavedScreenTemplate::factory()->create();

        $this->actingAs($this->user)->post(route('screens.store'), ['name' => 'Wall', 'saved_template' => $foreign->uuid])
            ->assertSessionHasErrors('saved_template');
    });
});

describe('editing and deleting a template', function () {
    beforeEach(function () {
        $this->template = SavedScreenTemplate::factory()->from($this->screen)->create();
    });

    it('is for team admins', function () {
        $this->actingAs($this->admin)->put(route('screen-templates.update', $this->template), ['name' => 'Night wall', 'description' => ''])
            ->assertSessionHas('status', 'Template saved.');

        expect($this->template->refresh()->only(['name', 'description']))->toBe(['name' => 'Night wall', 'description' => null]);

        $this->actingAs($this->admin)->delete(route('screen-templates.destroy', $this->template))
            ->assertSessionHas('status', 'Template Night wall deleted.');

        expect(SavedScreenTemplate::query()->count())->toBe(0)
            ->and($this->screen->exists)->toBeTrue();
    });

    it('is not for members', function () {
        $this->actingAs($this->user)->put(route('screen-templates.update', $this->template), ['name' => 'Night wall'])->assertForbidden();
        $this->actingAs($this->user)->delete(route('screen-templates.destroy', $this->template))->assertForbidden();

        expect($this->template->refresh()->name)->toBe('Ops');
    });

    it('shows admins the buttons for it', function () {
        $this->actingAs($this->admin)->get(route('screens.index'))->assertInertia(fn (Assert $page) => $page->where('can_manage_templates', true));
    });
});
