<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Screens\Actions\CopyScreenToTeamAction;
use App\Domains\Screens\Actions\ExportScreenAction;
use App\Domains\Screens\Actions\ImportScreenAction;
use App\Domains\Screens\Data\ScreenImport;
use App\Domains\Screens\Http\Controllers\ScreenFileController;
use App\Domains\Screens\Http\Requests\CopyScreenRequest;
use App\Domains\Screens\Http\Requests\ImportScreenRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Policies\ScreenPolicy;
use App\Domains\Screens\Support\ScreenFile;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\Teams\CreatesTeamMembers;

covers(
    ScreenFileController::class,
    ExportScreenAction::class,
    ImportScreenAction::class,
    ImportScreenRequest::class,
    CopyScreenToTeamAction::class,
    CopyScreenRequest::class,
    ScreenImport::class,
    ScreenFile::class,
    ScreenPolicy::class,
);

uses(CreatesTeamMembers::class);

beforeEach(function () {
    ['user' => $this->user, 'project' => $this->project] = $this->memberWithProject(TeamRole::Member);
    $this->project->update(['slug' => 'shop']);
    $this->check = HealthCheck::factory()->for($this->project)->reported('database:pgsql')->create();
    $this->website = HealthCheck::factory()->for($this->project)->monitored()->create();
    $this->screen = Screen::factory()->for($this->project->team)->scopedTo($this->project)
        ->withHealthWidget($this->check, $this->website)->create(['name' => 'Ops wall']);

    // Another team with the same slugs, and a member of it.
    ['user' => $this->other, 'project' => $this->otherProject] = $this->memberWithProject(TeamRole::Member);
    $this->otherProject->update(['slug' => 'shop']);
    $this->otherCheck = HealthCheck::factory()->for($this->otherProject)->reported('database:pgsql')->create();
    $this->otherWebsite = HealthCheck::factory()->for($this->otherProject)->monitored()->create();

    $this->file = fn (): array => app(ExportScreenAction::class)->execute($this->screen->refresh());
    $this->import = fn (array|string $file, ?User $user = null) => $this->actingAs($user ?? $this->other)
        ->post(route('screens.import'), ['json' => is_string($file) ? $file : json_encode($file)]);
});

describe('exporting a screen', function () {
    it('downloads it as a portable file named after it', function () {
        $response = $this->actingAs($this->user)->get(route('screens.export', $this->screen))->assertOk();

        expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="ops-wall.lumberjack-screen.json"')
            ->and($response->json('format'))->toBe('lumberjack-screen')
            ->and($response->json('version'))->toBe(1)
            ->and($response->json('screen.name'))->toBe('Ops wall')
            ->and($response->json('screen.scope.projects'))->toBe(['shop'])
            ->and($response->json('screen.widgets.3.options.checks'))->toBe([
                ['project' => 'shop', 'environment' => 'production', 'slug' => 'database:pgsql'],
                ['project' => 'shop', 'environment' => '', 'name' => 'Website'],
            ])
            ->and($response->json('screen.widgets.3.rotate.0.scope.projects'))->toBe(['shop']);
    });

    it('writes no ids, uuids, share keys or displays', function () {
        $this->screen->update(['share_key' => 'ls_'.str_repeat('a', 40), 'share_key_hash' => 'hash']);

        $json = $this->actingAs($this->user)->get(route('screens.export', $this->screen))->getContent();

        expect($json)->not->toContain($this->screen->uuid)
            ->not->toContain($this->project->uuid)
            ->not->toContain($this->check->uuid)
            ->not->toContain('ls_aaaa')
            ->not->toContain('share')
            ->not->toContain('team_id')
            ->not->toContain('display');
    });

    it('is only for members of the team', function () {
        $this->actingAs($this->other)->get(route('screens.export', $this->screen))->assertForbidden();
    });
});

describe('importing a screen', function () {
    it('gives an equal screen in another team, its references found by slug', function () {
        ($this->import)(($this->file)())->assertSessionHasNoErrors()->assertSessionHas('status', 'Screen imported.');

        $copy = $this->otherProject->team->screens()->sole();
        $original = $this->screen->refresh();

        expect($copy->uuid)->not->toBe($original->uuid)
            ->and($copy->share_key)->toBeNull()
            ->and($copy->only(['name', 'cols', 'rows', 'aspect', 'orientation', 'fit', 'show_header', 'theme', 'light_from', 'light_to', 'timezone']))
            ->toEqual($original->only(['name', 'cols', 'rows', 'aspect', 'orientation', 'fit', 'show_header', 'theme', 'light_from', 'light_to', 'timezone']))
            ->and($copy->scope)->toBe(['projects' => [$this->otherProject->uuid], 'environment' => 'production', 'period' => '7d'])
            ->and($copy->widgets[3]['options']['checks'])->toBe([$this->otherCheck->uuid, $this->otherWebsite->uuid])
            ->and($copy->widgets[3]['rotate'][0]['scope']['projects'])->toBe([$this->otherProject->uuid])
            ->and(array_map(fn (array $w): array => array_diff_key($w, ['options' => 1, 'rotate' => 1]), $copy->widgets))
            ->toBe(array_map(fn (array $w): array => array_diff_key($w, ['options' => 1, 'rotate' => 1]), $original->widgets));
    });

    it('opens the designer on the new screen', function () {
        ($this->import)(($this->file)())->assertRedirect(route('screens.edit', $this->otherProject->team->screens()->sole()));
    });

    it('reads an uploaded file', function () {
        $file = UploadedFile::fake()->createWithContent('ops.lumberjack-screen.json', (string) json_encode(($this->file)()));

        $this->actingAs($this->other)->post(route('screens.import'), ['file' => $file])->assertSessionHasNoErrors();

        expect($this->otherProject->team->screens()->count())->toBe(1);
    });

    it('leaves out the projects and checks the team has nothing for, and says which', function () {
        $this->otherProject->update(['slug' => 'store']);

        ($this->import)(($this->file)())->assertSessionHas('status', 'Screen imported. Not found in this team, so left out: project shop, check shop: database:pgsql, check shop: Website.');

        $copy = $this->otherProject->team->screens()->sole();

        expect($copy->scope['projects'])->toBe([])
            ->and($copy->widgets[3]['options'])->toBe(['only_problems' => true])
            ->and($copy->widgets[3]['rotate'])->toBe([['source' => 'checks', 'seconds' => 20, 'scope' => ['projects' => []]]]);
    });

    it('previews what it would create without creating it', function () {
        $this->otherWebsite->delete();

        $this->actingAs($this->other)->postJson(route('screens.import.preview'), ['json' => json_encode(($this->file)())])
            ->assertOk()
            ->assertJsonPath('name', 'Ops wall')
            ->assertJsonPath('cols', 4)
            ->assertJsonCount(4, 'widgets')
            ->assertJsonPath('dropped', ['projects' => [], 'checks' => ['shop: Website']]);

        expect($this->otherProject->team->screens()->count())->toBe(0);
    });

    it('gives widgets whose id is taken a new one', function () {
        $file = ($this->file)();
        $file['screen']['widgets'][1]['id'] = 'requests';

        ($this->import)($file)->assertSessionHasNoErrors();

        expect(array_column($this->otherProject->team->screens()->sole()->widgets, 'id'))->toBe(['requests', 'requests-2', 'clock', 'health']);
    });

    it('trusts no uuid in the file, not even one of the team', function () {
        $file = ($this->file)();
        $file['screen']['scope']['projects'] = [$this->otherProject->uuid];

        ($this->import)($file)->assertSessionHasNoErrors();

        expect($this->otherProject->team->screens()->sole()->scope['projects'])->toBe([]);
    });

    it('rejects what is not a screen file of this version', function (Closure $change, string $field, string $message) {
        $file = ($this->file)();
        $change($file);

        ($this->import)($file)->assertSessionHasErrors([$field => $message]);

        expect($this->otherProject->team->screens()->count())->toBe(0);
    })->with([
        'another format' => [function (array &$f) {
            $f['format'] = 'grafana-dashboard';
        }, 'format', 'This is not a Lumberjack screen file.'],
        'another version' => [function (array &$f) {
            $f['version'] = 2;
        }, 'version', 'This screen file is from another version of Lumberjack; this one reads version 1.'],
        'no screen' => [function (array &$f) {
            unset($f['screen']);
        }, 'screen', 'The screen field is required.'],
        'a grid too large' => [function (array &$f) {
            $f['screen']['cols'] = 40;
        }, 'screen.cols', 'The screen.cols field must not be greater than 12.'],
        'an unknown source' => [function (array &$f) {
            $f['screen']['widgets'][0]['source'] = 'bitcoin';
        }, 'screen.widgets.0.source', 'This widget cannot show that.'],
        'overlapping widgets' => [function (array &$f) {
            $f['screen']['widgets'][1]['x'] = 0;
        }, 'screen.widgets.1.x', 'This widget covers a cell another widget already uses.'],
    ]);

    it('rejects what is not JSON', function () {
        ($this->import)('{"format": ')->assertSessionHasErrors(['json' => 'This is not valid JSON.']);
    });

    it('reads files up to 256 KB', function () {
        $file = UploadedFile::fake()->create('big.json', ScreenFile::MAX_KILOBYTES + 1, 'application/json');

        $this->actingAs($this->other)->post(route('screens.import'), ['file' => $file])
            ->assertSessionHasErrors(['file' => 'The file is too large; a screen file is at most 256 KB.']);
    });

    it('asks for a file', function () {
        $this->actingAs($this->other)->post(route('screens.import'), [])->assertSessionHasErrors(['file' => 'Pick a file or paste its JSON.']);
    });
});

describe('copying a screen to another team', function () {
    beforeEach(function () {
        $this->otherProject->team->members()->attach($this->user, ['role' => TeamRole::Member->value]);
        $this->copy = fn (string $team) => $this->actingAs($this->user)->from(route('screens.index'))
            ->post(route('screens.copy', $this->screen), ['team' => $team]);
    });

    it('makes the screen there, its references found by slug', function () {
        ($this->copy)($this->otherProject->team->uuid)
            ->assertRedirect(route('screens.index'))
            ->assertSessionHas('status', "Ops wall copied to {$this->otherProject->team->name}.");

        $copy = $this->otherProject->team->screens()->sole();

        expect($copy->name)->toBe('Ops wall')
            ->and($copy->scope['projects'])->toBe([$this->otherProject->uuid])
            ->and($copy->widgets[3]['options']['checks'])->toBe([$this->otherCheck->uuid, $this->otherWebsite->uuid])
            ->and($this->project->team->screens()->count())->toBe(1);
    });

    it('names what the other team has no match for', function () {
        $this->otherCheck->delete();

        ($this->copy)($this->otherProject->team->uuid)
            ->assertSessionHas('status', "Ops wall copied to {$this->otherProject->team->name}. Not found in this team, so left out: check shop: database:pgsql.");
    });

    it('is only for teams the user is in', function () {
        $foreign = Screen::factory()->create()->team;

        ($this->copy)($foreign->uuid)->assertForbidden();
        ($this->copy)('00000000-0000-0000-0000-000000000000')->assertForbidden();

        expect($foreign->screens()->count())->toBe(1);
    });

    it('is only for screens the user can see', function () {
        $this->actingAs($this->other)->post(route('screens.copy', $this->screen), ['team' => $this->otherProject->team->uuid])->assertForbidden();
    });
});
