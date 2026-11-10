<?php

declare(strict_types=1);

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Http\Controllers\DataSourceController;
use App\Domains\DataSources\Http\Requests\SaveDataSourceRequest;
use App\Domains\DataSources\Jobs\FetchDataSource;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\DataSources\Policies\DataSourcePolicy;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Health\FakesHealthProbes;

covers(DataSourceController::class, SaveDataSourceRequest::class, DataSourcePolicy::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.allow_private' => false]);
    $this->fakeDns();
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->source = DataSource::factory()->for($this->project)->create([
        'headers' => [['name' => 'Authorization', 'value' => 'Bearer t0p-s3cret']],
        'last_body' => '{"orders":12}',
    ]);
    $this->form = fn (array $fields = []): array => [
        'name' => 'Stats', 'url' => 'https://example.com/stats.json', 'format' => 'json', 'interval_minutes' => 1, 'timeout_s' => 5, ...$fields,
    ];
});

describe('the page', function () {
    it('tells the page what group a connected source is in', function () {
        DataSource::factory()->for($this->project)->connected()->inGroup('Events > Storage')->create();

        $this->actingAs($this->admin)->get(route('data-sources.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('connected.0.group', 'Events > Storage'));
    });

    it('lists the sources with their header names, never a header value', function () {
        $response = $this->actingAs($this->member)->get(route('data-sources.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('data-sources/index')
                ->has('sources', 1)
                ->where('sources.0.header_names', ['Authorization'])
                ->where('sources.0.preview', '{"orders":12}')
                ->missing('sources.0.headers'));

        expect($response->getContent())->not->toContain('t0p-s3cret');
    });

    it('still opens with headers it cannot decrypt', function () {
        DB::table('data_sources')->where('id', $this->source->id)->update(['headers' => 'not encrypted under this key']);

        $this->actingAs($this->member)->get(route('data-sources.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sources.0.header_names', []));
    });

    it('shows the keys of the URL query, not their values', function () {
        $this->source->update(['url' => 'https://example.com/stats.json?token=s3cret&flag&v=2#top']);

        $response = $this->actingAs($this->member)->get(route('data-sources.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('sources.0.url', 'https://example.com/stats.json?token=***&***&v=***#top'));

        expect($response->getContent())->not->toContain('s3cret');
    });

    it('shows a calendar\'s address without its path, which holds its key', function () {
        $this->source->update(['format' => DataSourceFormat::Ical, 'url' => 'https://calendar.google.com/calendar/ical/team%40example.com/private-s3cret/basic.ics?hl=da']);

        $response = $this->actingAs($this->member)->get(route('data-sources.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('sources.0.url', 'https://calendar.google.com/***?hl=***'));

        expect($response->getContent())->not->toContain('s3cret');
    });

    it('gives the screen designer the sources by name and format only', function () {
        $screen = Screen::factory()->for($this->project->team)->create();

        $response = $this->actingAs($this->member)->get(route('screens.edit', $screen))
            ->assertInertia(fn (Assert $page) => $page->where('data_sources', [['uuid' => $this->source->uuid, 'name' => 'Orders API', 'format' => 'json', 'group' => null, 'host' => null, 'shape' => null, 'environment' => null, 'project' => $this->project->name, 'periods' => null]]));

        expect($response->getContent())->not->toContain('t0p-s3cret')
            ->and($response->getContent())->not->toContain('example.com/stats.json');
    });

    it('is closed to someone outside the team', function () {
        $this->actingAs(User::factory()->create())->get(route('data-sources.index', $this->project))->assertForbidden();
    });
});

describe('saving', function () {
    it('adds a source with its headers encrypted, fetched on the next minute', function () {
        $this->actingAs($this->admin)->post(route('data-sources.store', $this->project), ($this->form)([
            'headers' => [['name' => 'X-Api-Key', 'value' => 'k3y-value']],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $source = DataSource::query()->where('name', 'Stats')->sole();
        expect($source->headers)->toBe([['name' => 'X-Api-Key', 'value' => 'k3y-value']])
            ->and($source->next_fetch_at)->toBeNull()
            ->and((string) DB::table('data_sources')->where('id', $source->id)->value('headers'))->not->toContain('k3y-value');
    });

    it('keeps a header value left empty, and drops a header taken out', function () {
        $this->actingAs($this->admin)->put(route('data-sources.update', [$this->project, $this->source]), ($this->form)([
            'headers' => [['name' => 'Authorization', 'value' => ''], ['name' => 'Accept-Language', 'value' => 'da']],
        ]))->assertSessionHasNoErrors();

        expect($this->source->refresh()->headers)->toBe([['name' => 'Authorization', 'value' => 'Bearer t0p-s3cret'], ['name' => 'Accept-Language', 'value' => 'da']]);

        $this->actingAs($this->admin)->put(route('data-sources.update', [$this->project, $this->source]), ($this->form)())->assertSessionHasNoErrors();

        expect($this->source->refresh()->headers)->toBe([]);
    });

    it('keeps the URL when it comes back as the page showed it, with its query values masked', function () {
        $this->source->update(['url' => 'https://example.com/stats.json?token=s3cret']);

        $this->actingAs($this->admin)->put(route('data-sources.update', [$this->project, $this->source]), ($this->form)([
            'url' => 'https://example.com/stats.json?token=***',
        ]))->assertSessionHasNoErrors();

        expect($this->source->refresh()->url)->toBe('https://example.com/stats.json?token=s3cret');
    });

    it('asks again for a header value kept when the URL points at another origin', function (string $url) {
        $this->actingAs($this->admin)->put(route('data-sources.update', [$this->project, $this->source]), ($this->form)([
            'url' => $url,
            'headers' => [['name' => 'Authorization', 'value' => '']],
        ]))->assertSessionHasErrors(['headers.0.value' => 'The URL points at another server now: give Authorization its value again.']);

        expect($this->source->refresh()->headers)->toBe([['name' => 'Authorization', 'value' => 'Bearer t0p-s3cret']]);
    })->with([
        'host' => ['https://elsewhere.example.net/stats.json'],
        'scheme' => ['http://example.com/stats.json'],
        'port' => ['https://example.com:8443/stats.json'],
    ]);

    it('keeps a header value on another path of the same origin, the default port written out or not', function () {
        $this->actingAs($this->admin)->put(route('data-sources.update', [$this->project, $this->source]), ($this->form)([
            'url' => 'https://EXAMPLE.com:443/other.json',
            'headers' => [['name' => 'Authorization', 'value' => '']],
        ]))->assertSessionHasNoErrors();

        expect($this->source->refresh()->headers)->toBe([['name' => 'Authorization', 'value' => 'Bearer t0p-s3cret']]);
    });

    it('refuses a private URL, a new header without a value, an interval under a minute and an unknown format', function (array $fields, string $error) {
        $this->actingAs($this->admin)->post(route('data-sources.store', $this->project), ($this->form)($fields))->assertSessionHasErrors($error);
    })->with([
        'loopback' => [['url' => 'http://127.0.0.1/stats'], 'url'],
        'credentials in the URL' => [['url' => 'https://user:pass@example.com/stats'], 'url'],
        'user name in the URL' => [['url' => 'https://user@example.com/stats'], 'url'],
        'metadata service' => [['url' => 'http://169.254.169.254/latest'], 'url'],
        'header without a value' => [['headers' => [['name' => 'X-Api-Key', 'value' => '']]], 'headers.0.value'],
        'header with a line break' => [['headers' => [['name' => 'X-Api-Key', 'value' => "a\r\nHost: evil"]]], 'headers.0.value'],
        'Host header' => [['headers' => [['name' => 'Host', 'value' => 'evil']]], 'headers.0.name'],
        'interval' => [['interval_minutes' => 0], 'interval_minutes'],
        'unknown format' => [['format' => 'xml'], 'format'],
        'no format' => [['format' => null], 'format'],
    ]);

    it('takes a calendar\'s webcal link as the same address over HTTPS', function (string $link) {
        $this->actingAs($this->admin)->post(route('data-sources.store', $this->project), ($this->form)(['url' => $link, 'format' => 'ical']))
            ->assertSessionHasNoErrors();

        expect(DataSource::query()->where('name', 'Stats')->sole()->url)->toBe('https://example.com/team/basic.ics');
    })->with(['webcal://example.com/team/basic.ics', ' WEBCALS://example.com/team/basic.ics ']);

    it('saves the format, which the page shows', function () {
        $this->actingAs($this->admin)->put(route('data-sources.update', [$this->project, $this->source]), ($this->form)(['format' => 'prometheus', 'headers' => [['name' => 'Authorization', 'value' => '']]]))->assertSessionHasNoErrors();

        expect($this->source->refresh()->format)->toBe(DataSourceFormat::Prometheus);
        $this->actingAs($this->member)->get(route('data-sources.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('sources.0.format', 'prometheus'));
    });
});

describe('who may change sources', function () {
    it('lets whoever administers the project fetch one now and delete it', function () {
        Queue::fake();

        $this->actingAs($this->admin)->post(route('data-sources.fetch', [$this->project, $this->source]))->assertRedirect();
        Queue::assertPushed(FetchDataSource::class, fn (FetchDataSource $job): bool => $job->source->is($this->source));

        $this->actingAs($this->admin)->delete(route('data-sources.destroy', [$this->project, $this->source]))->assertRedirect();
        expect(DataSource::query()->count())->toBe(0);
    });

    it('keeps a member from adding, editing, fetching and deleting', function (string $method, string $route) {
        Queue::fake();
        $params = $route === 'data-sources.store' ? [$this->project] : [$this->project, $this->source];

        $this->actingAs($this->member)->{$method}(route($route, $params), ($this->form)())->assertForbidden();

        expect($this->source->refresh()->name)->toBe('Orders API');
        Queue::assertNothingPushed();
    })->with([
        'add' => ['post', 'data-sources.store'],
        'edit' => ['put', 'data-sources.update'],
        'fetch' => ['post', 'data-sources.fetch'],
        'delete' => ['delete', 'data-sources.destroy'],
    ]);

    it('finds no source of another project under this one', function (string $method, string $route) {
        Queue::fake();
        $other = DataSource::factory()->create();

        $this->actingAs($this->admin)->{$method}(route($route, [$this->project, $other]), ($this->form)())->assertNotFound();

        expect($other->refresh()->name)->toBe('Orders API');
        Queue::assertNothingPushed();
    })->with([
        'edit' => ['put', 'data-sources.update'],
        'fetch' => ['post', 'data-sources.fetch'],
        'delete' => ['delete', 'data-sources.destroy'],
    ]);

    it('keeps an admin of one team from the sources of another team', function (string $method, string $route) {
        Queue::fake();
        $other = DataSource::factory()->create();

        $this->actingAs($this->admin)->{$method}(route($route, [$other->project, $other]), ($this->form)())->assertForbidden();

        expect($other->refresh()->name)->toBe('Orders API');
        Queue::assertNothingPushed();
    })->with([
        'edit' => ['put', 'data-sources.update'],
        'fetch' => ['post', 'data-sources.fetch'],
        'delete' => ['delete', 'data-sources.destroy'],
    ]);
});

describe('connected sources', function () {
    beforeEach(fn () => $this->connected = DataSource::factory()->for($this->project)->connected()->create());

    it('cannot be edited, fetched by hand or removed while the app offers them', function () {
        $this->actingAs($this->admin);

        $this->put(route('data-sources.update', [$this->project, $this->connected]), ($this->form)())->assertNotFound();
        $this->post(route('data-sources.fetch', [$this->project, $this->connected]))->assertNotFound();
        $this->delete(route('data-sources.destroy', [$this->project, $this->connected]))->assertNotFound();
    });

    it('can be removed once the app no longer offers them', function () {
        $this->connected->update(['archived_at' => now()]);

        $this->actingAs($this->admin)->delete(route('data-sources.destroy', [$this->project, $this->connected]))->assertRedirect();

        expect(DataSource::query()->whereKey($this->connected->id)->exists())->toBeFalse();
    });

    it('cannot be created by hand', function () {
        $this->actingAs($this->admin)->post(route('data-sources.store', $this->project), ($this->form)(['format' => DataSourceFormat::Connected->value]))->assertSessionHasErrors('format');
    });

    it('read failing only for a period still being shown, with its reason', function () {
        $window = DataSourceWindow::factory()->forSource($this->connected)->create(['error' => 'Timed out', 'requested_at' => now()]);
        $status = fn (Assert $page, string $status, ?string $error) => $page->where('connected.0.status', $status)->where('connected.0.last_error', $error);

        $this->actingAs($this->member)->get(route('data-sources.index', $this->project))->assertInertia(fn (Assert $page) => $status($page, 'failing', 'Timed out'));

        $window->update(['requested_at' => now()->subHour()]);

        $this->get(route('data-sources.index', $this->project))->assertInertia(fn (Assert $page) => $status($page, 'connected', null));
    });

    it('are listed apart, with their connection and no token', function () {
        $response = $this->actingAs($this->member)->get(route('data-sources.index', $this->project))->assertInertia(fn (Assert $page) => $page
            ->has('sources', 1)
            ->where('connected.0.key', 'orders:total')
            ->where('connected.0.status', 'connected')
            ->where('connections.0.environment', 'production')
            ->where('connections.0.status', 'waiting')
            ->missing('connections.0.pull_token'));

        expect($response->getContent())->not->toContain('lj_pull_test');
    });

    it('still shows the page after APP_KEY changed', function () {
        DB::table('data_source_connections')->update(['pull_token' => 'not-encrypted']);

        $this->actingAs($this->admin)->get(route('data-sources.index', $this->project))->assertOk();
    });
});
