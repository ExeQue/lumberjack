<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Servers\LumberjackServer;
use App\Domains\Mcp\Support\ScreenAccess;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Actions\SaveScreenAction;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\ScreenVersion;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Tests\Support\Mcp\CallsMcpTools;

covers(LumberjackServer::class, ScreenAccess::class, SaveScreenAction::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->team = $this->project->team;
    $this->other = Project::factory()->for($this->team)->create();
    $this->user = User::factory()->inTeam($this->team)->create();
    $this->token = McpToken::factory()->for($this->team)->designsScreens()->withToken('lj_mcp_design')->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->screen = Screen::factory()->for($this->team)->scopedTo($this->project)->create(['cols' => 4, 'rows' => 4, 'widgets' => []]);
    $this->stat = fn (array $scope = []): array => ['id' => 'a', 'type' => 'stat', 'source' => 'error_rate', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 2, 'scope' => $scope];
});

describe('reading', function () {
    it('hides a screen showing a project outside the token', function () {
        $hidden = Screen::factory()->for($this->team)->scopedTo($this->other)->create(['widgets' => [($this->stat)()]]);

        $listed = $this->toolResult($this->callTool('lj_mcp_design', 'list_screens')->assertOk());
        expect(array_column($listed['screens'], 'uuid'))->toBe([$this->screen->uuid]);

        $this->callTool('lj_mcp_design', 'get_screen', ['screen' => $hidden->uuid])->assertJsonPath('result.isError', true);
    });

    it('hides a screen carrying a Wi-Fi password from a token not covering every project', function () {
        $wifi = Screen::factory()->for($this->team)->scopedTo($this->project)->withQrWidget(['password' => 'hunter2'])->create();

        $this->callTool('lj_mcp_design', 'get_screen', ['screen' => $wifi->uuid])->assertJsonPath('result.isError', true);
    });

    it('answers an argument of the wrong type with an error', function () {
        $this->callTool('lj_mcp_design', 'get_screen', ['screen' => ['x']])->assertJsonPath('result.isError', true);
        $this->callTool('lj_mcp_design', 'list_widget_types', ['type' => ['x']])->assertJsonPath('result.isError', true);
    });

    it('says where a data source comes from without its URL', function () {
        DataSource::factory()->for($this->project)->create(['url' => 'https://metrics.example.com/x?secret=1']);

        $source = $this->toolResult($this->callTool('lj_mcp_design', 'list_data_sources'))['data_sources'][0];

        expect($source['host'])->toBe('metrics.example.com')
            ->and($source['origin'])->toBe('endpoint')
            ->and(json_encode($source))->not->toContain('secret');
    });

    it('has a guide and a catalog', function () {
        expect($this->callTool('lj_mcp_design', 'get_screen_design_guide')->json('result.content.0.text'))->toContain('Revisions');

        $types = $this->toolResult($this->callTool('lj_mcp_design', 'list_widget_types'))['types'];
        expect(array_column($types, 'type'))->toContain('stat');
    });
});

describe('designing', function () {
    it('needs the token to be allowed', function () {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)]);

        expect($this->wasRefused($this->callTool('lj_mcp_design', 'update_screen', ['screen' => $this->screen->uuid, 'revision' => 0, 'name' => 'New'])))->toBeTrue();

        expect($this->screen->refresh()->name)->not->toBe('New');
    });

    it('saves a layout, attributed, and keeps what it was', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_design', 'update_screen_layout', [
            'screen' => $this->screen->uuid, 'revision' => 0, 'cols' => 4, 'rows' => 4, 'widgets' => [($this->stat)()],
        ])->assertOk());

        expect($result['revision'])->toBe(1)
            ->and($this->screen->refresh()->widgets)->toHaveCount(1)
            ->and(ScreenVersion::query()->sole())->mcp_token_id->toBe($this->token->id)->author->toBe($this->token->name);
    });

    it('refuses a stale revision', function () {
        $this->screen->update(['revision' => 3]);

        $this->callTool('lj_mcp_design', 'update_screen', ['screen' => $this->screen->uuid, 'revision' => 2, 'name' => 'New'])->assertJsonPath('result.isError', true);

        expect($this->screen->refresh()->name)->not->toBe('New');
    });

    it('refuses widening a screen beyond the token', function () {
        $this->callTool('lj_mcp_design', 'update_screen_layout', [
            'screen' => $this->screen->uuid, 'revision' => 0, 'cols' => 4, 'rows' => 4, 'widgets' => [($this->stat)(['projects' => [$this->other->uuid]])],
        ])->assertJsonPath('result.isError', true);

        expect($this->screen->refresh()->widgets)->toBe([]);
    });

    it('refuses a data source of a project outside the token', function () {
        $source = DataSource::factory()->for($this->other)->create();

        $this->callTool('lj_mcp_design', 'update_screen_layout', [
            'screen' => $this->screen->uuid, 'revision' => 0, 'cols' => 4, 'rows' => 4,
            'widgets' => [[...($this->stat)(), 'source' => 'external_json', 'options' => ['data_source' => $source->uuid, 'json_path' => '$.a']]],
        ])->assertJsonPath('result.isError', true);
    });

    it('restores a version', function () {
        $version = ScreenVersion::factory()->for($this->screen)->create(['snapshot' => ['name' => 'Before']]);

        $this->callTool('lj_mcp_design', 'restore_screen_version', ['screen' => $this->screen->uuid, 'revision' => 0, 'version' => $version->uuid])->assertOk();

        expect($this->screen->refresh())->name->toBe('Before')->revision->toBe(1);
    });

    it('keeps the current state when restoring within the same edit', function () {
        $save = app(SaveScreenAction::class);
        $this->screen->name = 'First';
        $save->execute($this->screen, $this->token);
        $this->screen->name = 'Second';
        $save->execute($this->screen, $this->token);
        expect(ScreenVersion::query()->count())->toBe(1);

        $this->callTool('lj_mcp_design', 'restore_screen_version', ['screen' => $this->screen->uuid, 'revision' => 2, 'version' => ScreenVersion::query()->sole()->uuid])->assertOk();

        expect(ScreenVersion::query()->latest('id')->first()->snapshot['name'])->toBe('Second');
    });

    it('prunes old versions', function () {
        foreach (range(1, SaveScreenAction::KEEP_VERSIONS + 2) as $i) {
            $this->screen->name = "Name {$i}";
            app(SaveScreenAction::class)->execute($this->screen);
        }

        expect(ScreenVersion::query()->whereBelongsTo($this->screen)->count())->toBe(SaveScreenAction::KEEP_VERSIONS);
    });
});

describe('previewing', function () {
    beforeEach(function () {
        $this->url = $this->toolResult($this->callTool('lj_mcp_design', 'preview_screen', ['screen' => $this->screen->uuid]))['url'];
    });

    it('shows the kiosk without a session', function () {
        $this->get($this->url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex')
            ->assertInertia(fn ($page) => $page->component('screens/kiosk')->where('share', true));
    });

    it('expires after five minutes', function () {
        $this->travel(6)->minutes();

        $this->get($this->url)->assertForbidden();
    });

    it('stops when its token expires', function () {
        $this->token->update(['expires_at' => now()->subMinute()]);

        $this->get($this->url)->assertForbidden();
    });

    it('stops with its token', function () {
        $this->token->delete();

        $this->get($this->url)->assertForbidden();
    });

    it('refuses a changed link', function () {
        $this->get(str_replace('token='.$this->token->id, 'token=999', $this->url))->assertForbidden();
    });

    it('serves data on the page\'s signed url', function () {
        $data = URL::temporarySignedRoute('screens.preview.data', now()->addMinute(), ['screen' => $this->screen->uuid, 'token' => $this->token->id], absolute: false);

        $this->getJson($data.'&widget=a')->assertOk();
    });
});

it('asks the designer to reload on a conflict', function () {
    $this->screen->update(['revision' => 2]);

    $this->actingAs($this->user)->putJson(route('screens.update', $this->screen), ['name' => 'X', 'revision' => 1])->assertStatus(409);
});
