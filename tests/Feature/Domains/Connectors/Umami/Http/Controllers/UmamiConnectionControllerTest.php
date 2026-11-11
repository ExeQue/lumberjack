<?php

declare(strict_types=1);

use App\Domains\Connectors\Umami\Actions\ConnectUmamiAction;
use App\Domains\Connectors\Umami\Actions\CreateUmamiAnnotationAction;
use App\Domains\Connectors\Umami\Actions\DeleteUmamiAnnotationAction;
use App\Domains\Connectors\Umami\Actions\ListUmamiWebsitesAction;
use App\Domains\Connectors\Umami\Actions\SaveUtmLabelAction;
use App\Domains\Connectors\Umami\Enums\UtmDimension;
use App\Domains\Connectors\Umami\Http\Controllers\UmamiConnectionController;
use App\Domains\Connectors\Umami\Http\Requests\ChangeUmamiWebsiteRequest;
use App\Domains\Connectors\Umami\Http\Requests\ConnectUmamiRequest;
use App\Domains\Connectors\Umami\Http\Requests\CreateUmamiAnnotationRequest;
use App\Domains\Connectors\Umami\Http\Requests\SaveUtmLabelRequest;
use App\Domains\Connectors\Umami\Http\Requests\UmamiCredentialsRequest;
use App\Domains\Connectors\Umami\Models\UtmLabel;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Health\FakesHealthProbes;

covers(UmamiConnectionController::class, UmamiCredentialsRequest::class, ConnectUmamiRequest::class, ChangeUmamiWebsiteRequest::class, ConnectUmamiAction::class, ListUmamiWebsitesAction::class, SaveUtmLabelRequest::class, SaveUtmLabelAction::class, CreateUmamiAnnotationRequest::class, CreateUmamiAnnotationAction::class, DeleteUmamiAnnotationAction::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.data_sources_allow_private' => false]);
    $this->fakeDns();
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->credentials = ['hosting' => 'self_hosted', 'url' => 'https://umami.example.com/', 'username' => 'admin', 'secret' => 'hunter2'];
    $this->refused = false;
    Http::fake([
        'umami.example.com/api/auth/login' => fn () => $this->refused ? Http::response(['error' => []], 401) : Http::response(['token' => 'jwt']),
        'umami.example.com/api/me/teams*' => Http::response(['data' => []]),
        'umami.example.com/api/websites/site-1' => Http::response(['id' => 'site-1', 'name' => 'Shop', 'domain' => null]),
        'umami.example.com/api/websites/site-2' => Http::response(['id' => 'site-2', 'name' => 'Blog', 'domain' => 'blog.example']),
        'umami.example.com/api/websites/nope' => Http::response('null'),
        'umami.example.com/api/websites*' => Http::response(['data' => [['id' => 'site-2', 'name' => 'Blog', 'domain' => 'blog.example'], ['id' => 'site-1', 'name' => 'Shop', 'domain' => null]]]),
    ]);
});

describe('finding the websites', function () {
    it('lists what the credentials can read, by name', function () {
        $this->actingAs($this->admin)->postJson(route('connectors.umami.websites', $this->project), $this->credentials)
            ->assertOk()
            ->assertExactJson(['websites' => [['id' => 'site-2', 'name' => 'Blog', 'domain' => 'blog.example', 'team' => null], ['id' => 'site-1', 'name' => 'Shop', 'domain' => null, 'team' => null]]]);
    });

    it('says on the secret when Umami refuses the credentials', function () {
        $this->refused = true;

        $this->actingAs($this->admin)->postJson(route('connectors.umami.websites', $this->project), $this->credentials)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('secret');
    });

    it('refuses an address it would not send credentials to', function (string $url) {
        $this->actingAs($this->admin)->postJson(route('connectors.umami.websites', $this->project), [...$this->credentials, 'url' => $url])
            ->assertJsonValidationErrors('url');
    })->with([
        'plain http' => ['http://umami.example.com'],
        'a private address' => ['https://10.0.0.5'],
    ]);

    it('is for those who administer the project', function () {
        $this->actingAs($this->member)->postJson(route('connectors.umami.websites', $this->project), $this->credentials)->assertForbidden();
    });
});

describe('connecting a website', function () {
    it('keeps the credentials encrypted and the website by name, and opens its page', function () {
        $response = $this->actingAs($this->admin)->post(route('connectors.umami.connection.store', $this->project), [...$this->credentials, 'website_id' => 'site-1', 'timezone' => 'Europe/Copenhagen'])
            ->assertSessionHasNoErrors();

        $connection = DataSourceConnection::query()->sole();

        $response->assertRedirect(route('connectors.umami.index', [$this->project, $connection]));
        expect($connection->kind)->toBe(ConnectionKind::Umami)
            ->and($connection->url)->toBe('https://umami.example.com/api')
            ->and($connection->name)->toBe('Shop')
            ->and($connection->timezone)->toBe('Europe/Copenhagen')
            ->and($connection->umamiConfig()->toArray())->toBe(['website_id' => 'site-1', 'username' => 'admin', 'secret' => 'hunter2'])
            ->and(DB::table('data_source_connections')->value('config'))->not->toContain('hunter2')->not->toContain('site-1')
            ->and(array_keys($connection->toArray()))->not->toContain('config');
    });

    it('reads as many websites as are connected, each apart, and renews one connected again', function () {
        $this->actingAs($this->admin);
        $this->post(route('connectors.umami.connection.store', $this->project), [...$this->credentials, 'website_id' => 'site-1']);
        $this->post(route('connectors.umami.connection.store', $this->project), [...$this->credentials, 'website_id' => 'site-2']);
        $this->post(route('connectors.umami.connection.store', $this->project), [...$this->credentials, 'website_id' => 'site-1', 'secret' => 'renewed']);

        $connections = $this->project->umamiConnections()->get();

        expect($connections->pluck('name')->all())->toBe(['Blog', 'Shop'])
            ->and($connections->pluck('environment')->unique())->toHaveCount(2)
            ->and($connections->first(fn (DataSourceConnection $c): bool => $c->umamiWebsiteId() === 'site-1')->umamiConfig()->secret)->toBe('renewed');
    });

    it('refuses a website the credentials cannot read', function () {
        $this->actingAs($this->admin)->post(route('connectors.umami.connection.store', $this->project), [...$this->credentials, 'website_id' => 'nope'])
            ->assertSessionHasErrors('website_id');

        expect(DataSourceConnection::query()->count())->toBe(0);
    });

    it('connects another website with the credentials a connection has', function () {
        $connection = DataSourceConnection::factory()->umami('site-1')->for($this->project)->create();

        $this->actingAs($this->admin)->post(route('connectors.umami.connection.add', [$this->project, $connection]), ['website_id' => 'site-2'])->assertSessionHasNoErrors();

        $connections = $this->project->umamiConnections()->get();

        expect($connections->map->umamiWebsiteId()->all())->toBe(['site-2', 'site-1'])
            ->and($connections->first()->umamiConfig()->secret)->toBe('umami_key');
    });

    it('moves a connection to another website, forgetting what was read from the first', function () {
        $connection = DataSourceConnection::factory()->umami('site-1')->for($this->project)->create();
        $source = DataSource::factory()->create(['project_id' => $this->project->id, 'data_source_connection_id' => $connection->id, 'key' => 'visitors', 'environment' => $connection->environment]);
        DataSourceWindow::query()->create(['data_source_id' => $source->id, 'period' => '24h', 'bucket' => 3600, 'requested_at' => now(), 'data' => 5, 'has_data' => true]);

        $this->actingAs($this->admin)->put(route('connectors.umami.connection.update', [$this->project, $connection]), ['website_id' => 'site-2'])->assertSessionHasNoErrors();

        expect($connection->refresh()->name)->toBe('Blog')
            ->and($connection->umamiWebsiteId())->toBe('site-2')
            ->and(DataSourceConnection::query()->count())->toBe(1)
            ->and(DataSourceWindow::query()->count())->toBe(0)
            ->and($source->fresh())->not->toBeNull();
    });

    it('does not move a connection to a website another one reads', function () {
        $connection = DataSourceConnection::factory()->umami('site-1')->for($this->project)->create();
        DataSourceConnection::factory()->umami('site-2')->for($this->project)->create();

        $this->actingAs($this->admin)->put(route('connectors.umami.connection.update', [$this->project, $connection]), ['website_id' => 'site-2'])
            ->assertSessionHasErrors(['website_id' => __('connectors.umami.errors.already_connected')]);

        expect($connection->refresh()->umamiWebsiteId())->toBe('site-1');
    });

    it('lists the websites of the saved credentials without sending them to the browser', function () {
        $connection = DataSourceConnection::factory()->umami()->for($this->project)->create();

        $response = $this->actingAs($this->admin)->getJson(route('connectors.umami.connection.websites', [$this->project, $connection]))->assertOk();

        expect($response->getContent())->not->toContain('umami_key')->and($response->json('websites'))->toHaveCount(2);
    });

    it('goes on to the first website when one is disconnected from its own page', function () {
        $connection = DataSourceConnection::factory()->umami()->for($this->project)->create();

        $this->actingAs($this->admin)->from(route('connectors.umami.index', [$this->project, $connection]))
            ->delete(route('data-source-connections.destroy', [$this->project, $connection]))
            ->assertRedirect(route('connectors.umami.index', $this->project));

        $this->actingAs($this->admin)->from(route('data-sources.index', $this->project))
            ->delete(route('data-source-connections.destroy', [$this->project, DataSourceConnection::factory()->umami('site-2')->for($this->project)->create()]))
            ->assertRedirect(route('data-sources.index', $this->project));
    });

    it('knows no website on an app\'s connection', function () {
        $app = DataSourceConnection::factory()->for($this->project)->create();

        $this->actingAs($this->admin)->getJson(route('connectors.umami.connection.websites', [$this->project, $app]))->assertNotFound();
        $this->actingAs($this->admin)->put(route('connectors.umami.connection.update', [$this->project, $app]), ['website_id' => 'site-2'])->assertNotFound();
    });
});

describe('the address of an app, which an Umami connection has none of', function () {
    it('cannot be changed or have its pull token rotated', function () {
        $connection = DataSourceConnection::factory()->umami()->for($this->project)->create();

        $this->actingAs($this->admin)->post(route('data-source-connections.rotate', [$this->project, $connection]))->assertNotFound();
        $this->actingAs($this->admin)->patch(route('data-source-connections.update', [$this->project, $connection]), ['url' => 'https://evil.example/x'])->assertNotFound();
    });

    it('cannot be taken by an app', function (string $environment) {
        $this->actingAs($this->admin)->post(route('data-source-connections.store', $this->project), ['environment' => $environment, 'url' => 'https://app.example.com/x'])
            ->assertSessionHasErrors('environment');
    })->with(['umami', 'umami-abc123', 'Umami-X']);
});

describe('naming a UTM value', function () {
    beforeEach(function () {
        $this->connection = DataSourceConnection::factory()->umami()->for($this->project)->create(['next_check_at' => now()->addMinutes(5)]);
        $this->name = fn (?string $label) => $this->put(route('connectors.umami.utm-labels.update', [$this->project, $this->connection]), ['dimension' => 'utmCampaign', 'value' => '5251806', 'label' => $label]);
    });

    it('keeps the name, and asks Umami again so screens get it as a source', function () {
        $this->actingAs($this->admin);
        ($this->name)('Black Friday')->assertSessionHasNoErrors();
        ($this->name)('Black Friday 2026');

        expect(UtmLabel::query()->sole()->only(['dimension', 'value', 'label']))->toBe(['dimension' => UtmDimension::Campaign, 'value' => '5251806', 'label' => 'Black Friday 2026'])
            ->and($this->connection->refresh()->next_check_at)->toBeNull();
    });

    it('forgets the name when given none', function () {
        UtmLabel::factory()->for($this->connection, 'dataSourceConnection')->create(['value' => '5251806']);

        $this->actingAs($this->admin);
        ($this->name)('');

        expect(UtmLabel::query()->count())->toBe(0);
    });

    it('is for those who administer the project', function () {
        $this->actingAs($this->member);

        ($this->name)('Black Friday')->assertForbidden();
    });

    it('refuses a breakdown that is not a UTM parameter', function () {
        $this->actingAs($this->admin)->put(route('connectors.umami.utm-labels.update', [$this->project, $this->connection]), ['dimension' => 'referrer', 'value' => 'x', 'label' => 'y'])
            ->assertSessionHasErrors('dimension');
    });
});

describe('noting a moment on the website', function () {
    beforeEach(function () {
        $this->connection = DataSourceConnection::factory()->umami()->for($this->project)->create(['timezone' => 'Europe/Copenhagen']);
        $this->cacheKey = 'umami-annotations:'.$this->connection->id.':site-1';
        Cache::put($this->cacheKey, [], 300);
    });

    it('writes a whole day as its date, and shows the new note at once', function () {
        $this->actingAs($this->admin)->post(route('connectors.umami.annotations.store', [$this->project, $this->connection]), ['date' => '2026-11-27', 'note' => ' Black Friday live '])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/websites/site-1/annotations')
            && $request->data() === ['date' => '2026-11-27', 'allDay' => true, 'note' => 'Black Friday live']);
        expect(Cache::has($this->cacheKey))->toBeFalse();
    });

    it('writes a moment in the connection\'s zone as UTC', function () {
        $this->actingAs($this->admin)->post(route('connectors.umami.annotations.store', [$this->project, $this->connection]), ['date' => '2026-11-27', 'time' => '09:30', 'note' => 'Mail sent']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['date'] === '2026-11-27T08:30:00Z' && $request['allDay'] === false);
    });

    it('removes a note, in Umami too', function () {
        $this->actingAs($this->admin)->delete(route('connectors.umami.annotations.destroy', [$this->project, $this->connection, 'note-1']))
            ->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/websites/site-1/annotations/note-1'));
        expect(Cache::has($this->cacheKey))->toBeFalse();
    });

    it('lets only those who administer the project write or remove notes', function () {
        $this->actingAs($this->member)->post(route('connectors.umami.annotations.store', [$this->project, $this->connection]), ['date' => '2026-11-27', 'note' => 'x'])->assertForbidden();
        $this->actingAs($this->member)->delete(route('connectors.umami.annotations.destroy', [$this->project, $this->connection, 'note-1']))->assertForbidden();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/annotations'));
    });
});
