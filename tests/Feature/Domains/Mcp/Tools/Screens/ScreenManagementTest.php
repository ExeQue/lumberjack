<?php

declare(strict_types=1);

use App\Domains\Mcp\Data\McpAreaGrant;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Screens\AssignDisplay;
use App\Domains\Mcp\Tools\Screens\DeleteScreen;
use App\Domains\Mcp\Tools\Screens\DuplicateScreen;
use App\Domains\Mcp\Tools\Screens\ExportScreen;
use App\Domains\Mcp\Tools\Screens\ImportScreen;
use App\Domains\Mcp\Tools\Screens\ListDisplays;
use App\Domains\Mcp\Tools\Screens\ListScreenViewers;
use App\Domains\Mcp\Tools\Screens\ReloadScreen;
use App\Domains\Mcp\Tools\Screens\SetScreenShare;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Actions\ExportScreenAction;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Mcp\CallsMcpTools;

covers(ExportScreen::class, ListDisplays::class, ListScreenViewers::class, DuplicateScreen::class, ReloadScreen::class, ImportScreen::class, AssignDisplay::class, SetScreenShare::class, DeleteScreen::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->team = $this->project->team;
    $this->other = Project::factory()->for($this->team)->create();
    $this->user = User::factory()->inTeam($this->team)->create();
    $this->token = McpToken::factory()->for($this->team)->allowing(McpArea::Screens, McpLevel::Manage)->withToken('lj_mcp_screens')->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->screen = Screen::factory()->for($this->team)->scopedTo($this->project)->create(['name' => 'Wall', 'cols' => 4, 'rows' => 4, 'widgets' => []]);
    $this->hidden = Screen::factory()->for($this->team)->scopedTo($this->other)->create(['widgets' => [['id' => 'a', 'type' => 'stat', 'source' => 'error_rate', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]]);
    $this->level = fn (McpLevel $level): McpPermissions => McpPermissions::everywhere(McpLevel::Read)->with(McpArea::Screens, new McpAreaGrant($level));
    $this->readOnly = fn () => $this->token->update(['permissions' => ($this->level)(McpLevel::Read)]);
});

describe('reading', function () {
    it('exports a screen as the web export does', function () {
        $file = $this->toolResult($this->callTool('lj_mcp_screens', 'export_screen', ['screen' => $this->screen->uuid])->assertOk());

        expect($file['screen']['name'])->toBe('Wall')
            ->and($file['screen']['scope']['projects'])->toBe(['shop']);
    });

    it('does not export a screen with a Wi-Fi password to a token not covering every project', function () {
        $wifi = Screen::factory()->for($this->team)->scopedTo($this->project)->withQrWidget(['password' => 'hunter2'])->create();

        $response = $this->callTool('lj_mcp_screens', 'export_screen', ['screen' => $wifi->uuid]);

        expect($this->wasRefused($response))->toBeTrue()
            ->and($response->getContent())->not->toContain('hunter2');
    });

    it('lists displays without credentials, hiding screens outside the token', function () {
        $shown = Display::factory()->showing($this->screen)->create(['name' => 'Lobby']);
        Display::factory()->showing($this->hidden)->onDevice($shown->device, 2)->create();

        $response = $this->callTool('lj_mcp_screens', 'list_displays')->assertOk();
        $device = $this->toolResult($response)['devices'][0];

        expect(array_column($device['displays'], 'screens'))->toBe([[$this->screen->uuid], ['hidden']])
            ->and($device['displays'][0]['name'])->toBe('Lobby')
            ->and($response->getContent())->not->toContain((string) $shown->token_hash);
    });

    it('counts viewers, null without a broadcaster', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_screens', 'list_screen_viewers', ['screen' => $this->screen->uuid])->assertOk());

        expect($result)->toBe(['screen' => $this->screen->uuid, 'viewers' => null]);
    });
});

describe('writing', function () {
    it('duplicates and reloads a screen', function () {
        $copy = $this->toolResult($this->callTool('lj_mcp_screens', 'duplicate_screen', ['screen' => $this->screen->uuid])->assertOk());
        expect(Screen::query()->where('uuid', $copy['screen'])->exists())->toBeTrue();

        $this->callTool('lj_mcp_screens', 'reload_screen', ['screen' => $this->screen->uuid])->assertJsonPath('result.isError', false);
        // A second reload moments later is refused.
        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'reload_screen', ['screen' => $this->screen->uuid])))->toBeTrue();
    });

    it('refuses a read token', function () {
        ($this->readOnly)();

        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'duplicate_screen', ['screen' => $this->screen->uuid])))->toBeTrue()
            ->and(Screen::query()->count())->toBe(2);
    });

    it('refuses when the maker may not change the screen', function () {
        Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);

        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'reload_screen', ['screen' => $this->screen->uuid])))->toBeTrue();
    });
});

describe('managing', function () {
    it('imports an exported screen', function () {
        $file = $this->toolResult($this->callTool('lj_mcp_screens', 'export_screen', ['screen' => $this->screen->uuid]));

        $result = $this->toolResult($this->callTool('lj_mcp_screens', 'import_screen', ['json' => json_encode($file)])->assertOk());

        expect(Screen::query()->where('uuid', $result['screen'])->sole()->scope['projects'])->toBe([$this->project->uuid]);
    });

    it('refuses an import showing projects outside the token', function () {
        $file = app(ExportScreenAction::class)->execute($this->hidden);

        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'import_screen', ['json' => json_encode($file)])))->toBeTrue()
            ->and(Screen::query()->count())->toBe(2);
    });

    it('refuses an import when the maker may not create screens', function () {
        Gate::before(fn ($user, string $ability) => $ability === 'create' ? false : null);
        $file = $this->toolResult($this->callTool('lj_mcp_screens', 'export_screen', ['screen' => $this->screen->uuid]));

        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'import_screen', ['json' => json_encode($file)])))->toBeTrue();
    });

    it('assigns a display a screen the token covers, and only that', function () {
        $display = Display::factory()->for($this->team)->create();

        $this->callTool('lj_mcp_screens', 'assign_display', ['display' => $display->uuid, 'screen' => $this->screen->uuid])->assertJsonPath('result.isError', false);
        expect($display->refresh()->screen_id)->toBe($this->screen->id);

        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'assign_display', ['display' => $display->uuid, 'screen' => $this->hidden->uuid])))->toBeTrue()
            ->and($display->refresh()->screen_id)->toBe($this->screen->id);
    });

    it('refuses assigning a display when the maker may not update it', function () {
        Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'update' && ($arguments[0] ?? null) instanceof Display ? false : null);
        $display = Display::factory()->for($this->team)->create();

        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'assign_display', ['display' => $display->uuid, 'screen' => $this->screen->uuid])))->toBeTrue()
            ->and($display->refresh()->screen_id)->toBeNull();
    });

    it('shares and unshares a screen without returning the link', function () {
        $response = $this->callTool('lj_mcp_screens', 'set_screen_share', ['screen' => $this->screen->uuid, 'enabled' => true])->assertOk();

        expect($this->toolResult($response)['shared'])->toBeTrue()
            ->and($response->getContent())->not->toContain((string) $this->screen->refresh()->share_key);

        $this->callTool('lj_mcp_screens', 'set_screen_share', ['screen' => $this->screen->uuid, 'enabled' => false])->assertOk();
        expect($this->screen->refresh()->share_key_hash)->toBeNull();
    });

    it('refuses sharing when the maker may not share', function () {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'share' ? false : null);

        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'set_screen_share', ['screen' => $this->screen->uuid, 'enabled' => true])))->toBeTrue()
            ->and($this->screen->refresh()->share_key_hash)->toBeNull();
    });

    it('deletes a screen', function () {
        $this->callTool('lj_mcp_screens', 'delete_screen', ['screen' => $this->screen->uuid])->assertJsonPath('result.isError', false);

        expect(Screen::query()->whereKey($this->screen->id)->exists())->toBeFalse();
    });

    it('refuses deleting with a write token or when the maker may not', function () {
        $this->token->update(['permissions' => ($this->level)(McpLevel::Write)]);
        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'delete_screen', ['screen' => $this->screen->uuid])))->toBeTrue();

        $this->token->update(['permissions' => ($this->level)(McpLevel::Manage)]);
        Gate::before(fn ($user, string $ability) => $ability === 'delete' ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_screens', 'delete_screen', ['screen' => $this->screen->uuid])))->toBeTrue()
            ->and(Screen::query()->whereKey($this->screen->id)->exists())->toBeTrue();
    });
});
