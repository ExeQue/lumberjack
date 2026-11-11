<?php

declare(strict_types=1);

use App\Domains\DataSources\Actions\ChangeDataSourceConnectionUrlAction;
use App\Domains\DataSources\Actions\CreateDataSourceConnectionAction;
use App\Domains\DataSources\Actions\RotatePullTokenAction;
use App\Domains\DataSources\Http\Controllers\DataSourceConnectionController;
use App\Domains\DataSources\Http\Requests\SaveDataSourceConnectionRequest;
use App\Domains\DataSources\Http\Requests\UpdateDataSourceConnectionRequest;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Health\FakesHealthProbes;

covers(DataSourceConnectionController::class, SaveDataSourceConnectionRequest::class, UpdateDataSourceConnectionRequest::class, CreateDataSourceConnectionAction::class, ChangeDataSourceConnectionUrlAction::class, RotatePullTokenAction::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.allow_private' => false, 'health.data_sources_allow_private' => false]);
    $this->fakeDns();
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->form = ['environment' => 'production', 'url' => 'https://app.example.com/lumberjack/data-sources'];
});

describe('connecting an app', function () {
    it('shows the token once, and keeps it encrypted', function () {
        $this->actingAs($this->admin)->post(route('data-source-connections.store', $this->project), $this->form)->assertSessionHas('secret');

        $connection = DataSourceConnection::query()->sole();

        expect($connection->pull_token)->toStartWith('lj_pull_')
            ->and(DB::table('data_source_connections')->value('pull_token'))->not->toContain('lj_pull_')
            ->and(array_keys($connection->toArray()))->not->toContain('pull_token');
    });

    it('refuses an address it cannot pull or sign', function (string $url) {
        $this->actingAs($this->admin)->post(route('data-source-connections.store', $this->project), [...$this->form, 'url' => $url])->assertSessionHasErrors('url');
    })->with([
        'plain http' => ['http://app.example.com/x'],
        'credentials' => ['https://user:pw@app.example.com/x'],
        'its own query' => ['https://app.example.com/x?tenant=1'],
        'a fragment' => ['https://app.example.com/x#y'],
        'a private address' => ['https://10.0.0.5/x'],
    ]);

    it('drops a trailing slash, as the app signs without one', function () {
        $this->actingAs($this->admin)->post(route('data-source-connections.store', $this->project), [...$this->form, 'url' => $this->form['url'].'/']);

        expect(DataSourceConnection::query()->sole()->url)->toBe($this->form['url']);
    });

    it('takes one connection per environment', function () {
        DataSourceConnection::factory()->for($this->project)->create();

        $this->actingAs($this->admin)->post(route('data-source-connections.store', $this->project), $this->form)->assertSessionHasErrors('environment');
    });

    it('cannot be done by a member', function () {
        $this->actingAs($this->member)->post(route('data-source-connections.store', $this->project), $this->form)->assertForbidden();
    });
});

describe('rotating', function () {
    beforeEach(fn () => $this->connection = DataSourceConnection::factory()->for($this->project)->create(['pull_token' => 'lj_pull_old']));

    it('shows the new token and goes on with the old one', function () {
        $this->actingAs($this->admin)->post(route('data-source-connections.rotate', [$this->project, $this->connection]))->assertSessionHas('secret');

        $connection = $this->connection->refresh();

        expect($connection->pull_token)->not->toBe('lj_pull_old')->and($connection->activeToken())->toBe('lj_pull_old');
    });

    it('keeps what the app holds when rotated twice before a deploy', function () {
        $this->actingAs($this->admin);
        $this->post(route('data-source-connections.rotate', [$this->project, $this->connection]));
        $this->post(route('data-source-connections.rotate', [$this->project, $this->connection]));

        expect($this->connection->refresh()->activeToken())->toBe('lj_pull_old');
    });

    it('works after APP_KEY changed', function () {
        DB::table('data_source_connections')->update(['pull_token' => 'not-encrypted']);

        $this->actingAs($this->admin)->post(route('data-source-connections.rotate', [$this->project, $this->connection]))->assertSessionHas('secret');

        expect($this->connection->refresh()->pull_token)->toStartWith('lj_pull_');
    });

    it('cannot reach another project\'s connection', function () {
        $other = DataSourceConnection::factory()->create();

        $this->actingAs($this->admin)->post(route('data-source-connections.rotate', [$this->project, $other]))->assertNotFound();
    });
});

describe('disconnecting', function () {
    it('removes the connection and its sources', function () {
        $source = DataSource::factory()->for($this->project)->connected()->create();

        $this->actingAs($this->admin)->delete(route('data-source-connections.destroy', [$this->project, $source->dataSourceConnection]))->assertRedirect();

        expect(DataSource::query()->whereKey($source->id)->exists())->toBeFalse()
            ->and(DataSourceConnection::query()->count())->toBe(0);
    });
});

describe('changing the address', function () {
    beforeEach(function () {
        $this->connection = DataSourceConnection::factory()->for($this->project)->create();
        $this->source = DataSource::factory()->for($this->project)->connected()->create(['data_source_connection_id' => $this->connection->id]);
        $this->change = fn (User $user, string $url) => $this->actingAs($user)->patch(route('data-source-connections.update', [$this->project, $this->connection]), ['url' => $url]);
    });

    it('moves it, keeping the token and sources', function () {
        $token = $this->connection->getRawOriginal('pull_token');

        ($this->change)($this->admin, 'https://new.example.com/lumberjack/datasources/')->assertRedirect();

        expect($this->connection->refresh())->url->toBe('https://new.example.com/lumberjack/datasources')
            ->and($this->connection->getRawOriginal('pull_token'))->toBe($token)
            ->and($this->source->fresh()->url)->toBe('https://new.example.com/lumberjack/datasources');
    });

    it('changes its time zone, also to a name the browser uses, and refuses an unknown one', function () {
        $patch = fn (string $timezone) => $this->actingAs($this->admin)->patch(route('data-source-connections.update', [$this->project, $this->connection]), ['url' => $this->connection->url, 'timezone' => $timezone]);

        // The old names a browser may use; which ones PHP knows follows the machine's time zone data.
        $legacy = array_values(array_diff(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), DateTimeZone::listIdentifiers()))[0] ?? 'Europe/Copenhagen';

        $patch($legacy)->assertSessionHasNoErrors();
        expect($this->connection->refresh()->timezone)->toBe($legacy);

        $patch('Mars/Olympus')->assertSessionHasErrors('timezone');
    });

    it('refuses an address that holds a query, and a member', function () {
        ($this->change)($this->admin, 'https://new.example.com/x?a=1')->assertSessionHasErrors('url');
        ($this->change)($this->member, 'https://new.example.com/x')->assertForbidden();
    });
});
