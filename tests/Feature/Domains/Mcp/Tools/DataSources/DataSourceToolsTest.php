<?php

declare(strict_types=1);

use App\Domains\DataSources\Http\Requests\SaveDataSourceConnectionRequest;
use App\Domains\DataSources\Http\Requests\SaveDataSourceRequest;
use App\Domains\DataSources\Jobs\FetchDataSource as FetchDataSourceJob;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Mcp\Data\McpAreaGrant;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Concerns\UsesDataSources;
use App\Domains\Mcp\Tools\DataSources\CreateDataSource;
use App\Domains\Mcp\Tools\DataSources\CreateDataSourceConnection;
use App\Domains\Mcp\Tools\DataSources\DeleteDataSource;
use App\Domains\Mcp\Tools\DataSources\DeleteDataSourceConnection;
use App\Domains\Mcp\Tools\DataSources\FetchDataSource;
use App\Domains\Mcp\Tools\DataSources\ListDataSourceConnections;
use App\Domains\Mcp\Tools\DataSources\PreviewDataSource;
use App\Domains\Mcp\Tools\DataSources\UpdateDataSource;
use App\Domains\Mcp\Tools\DataSources\UpdateDataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Health\FakesHealthProbes;
use Tests\Support\Mcp\CallsMcpTools;

covers(
    PreviewDataSource::class,
    ListDataSourceConnections::class,
    CreateDataSource::class,
    UpdateDataSource::class,
    FetchDataSource::class,
    CreateDataSourceConnection::class,
    UpdateDataSourceConnection::class,
    DeleteDataSource::class,
    DeleteDataSourceConnection::class,
    UsesDataSources::class,
    SaveDataSourceRequest::class,
    SaveDataSourceConnectionRequest::class,
);

uses(CallsMcpTools::class, FakesHealthProbes::class);

beforeEach(function () {
    config(['health.allow_private' => false]);
    $this->fakeDns();
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')
        ->allowing(McpArea::DataSources, McpLevel::Manage)->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->source = DataSource::factory()->for($this->project)->create([
        'headers' => [['name' => 'Authorization', 'value' => 'Bearer t0p-s3cret']],
        'last_body' => '{"orders":12}',
        'last_ok_at' => now(),
    ]);
    $this->connection = DataSourceConnection::factory()->for($this->project)->answering()->create();
    $this->denyUpdates = fn () => Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);
    $this->limitTo = fn (McpLevel $level) => $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)->with(McpArea::DataSources, new McpAreaGrant($level))]);
    $this->arguments = fn (array $arguments): array => array_map(fn (mixed $value): mixed => match ($value) {
        'source' => $this->source->uuid,
        'connection' => $this->connection->uuid,
        default => $value,
    }, $arguments);
});

describe('reading data sources', function () {
    it('previews the latest answer with the paths a widget can read', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'preview_data_source', ['data_source' => $this->source->uuid, 'path' => '$.orders'])->assertOk());

        expect($result['suggestions'][0]['path'])->toBe('$.orders')
            ->and($result['result']['value'])->toEqual(12);
    });

    it('lists connections without their URL or pull token', function () {
        $response = $this->callTool('lj_mcp_secret', 'list_data_source_connections')->assertOk();

        expect($this->toolResult($response)['connections'][0])->toMatchArray(['uuid' => $this->connection->uuid, 'host' => 'app.example.com', 'status' => 'connected'])
            ->and($response->getContent())->not->toContain('lj_pull_test')->not->toContain('/lumberjack/data-sources');
    });
});

describe('changing fetched sources', function () {
    it('adds a source and never returns a header value', function () {
        $response = $this->callTool('lj_mcp_secret', 'create_data_source', [
            'name' => 'Stats', 'url' => 'https://example.com/stats.json', 'format' => 'json', 'interval_minutes' => 1, 'timeout_s' => 5,
            'headers' => [['name' => 'X-Key', 'value' => 'n3w-s3cret']],
        ])->assertOk();

        expect($this->toolResult($response)['header_names'])->toBe(['X-Key'])
            ->and($response->getContent())->not->toContain('n3w-s3cret')
            ->and(DataSource::query()->where('name', 'Stats')->sole()->headers)->toBe([['name' => 'X-Key', 'value' => 'n3w-s3cret']]);
    });

    it('keeps the header values when only the name changes', function () {
        $this->callTool('lj_mcp_secret', 'update_data_source', ['data_source' => $this->source->uuid, 'name' => 'Renamed'])->assertOk();

        expect($this->source->refresh())->name->toBe('Renamed')
            ->headers->toBe([['name' => 'Authorization', 'value' => 'Bearer t0p-s3cret']]);
    });

    it('refuses to keep a header value for another server', function () {
        $response = $this->callTool('lj_mcp_secret', 'update_data_source', ['data_source' => $this->source->uuid, 'url' => 'https://other.example.org/stats.json']);

        expect($this->wasRefused($response))->toBeTrue()
            ->and($this->source->refresh()->url)->toBe('https://example.com/stats.json');
    });

    it('queues a fetch now', function () {
        Queue::fake();

        $this->callTool('lj_mcp_secret', 'fetch_data_source', ['data_source' => $this->source->uuid])->assertOk();

        Queue::assertPushed(FetchDataSourceJob::class);
    });

    it('deletes a source, but not one its app still offers', function () {
        $offered = DataSource::factory()->for($this->project)->connected()->create();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_data_source', ['data_source' => $offered->uuid])))->toBeTrue();
        $this->callTool('lj_mcp_secret', 'delete_data_source', ['data_source' => $this->source->uuid])->assertOk();

        expect(DataSource::query()->pluck('id')->all())->toBe([$offered->id]);
    });
});

describe('changing connections', function () {
    it('connects an app without returning its pull token', function () {
        $response = $this->callTool('lj_mcp_secret', 'create_data_source_connection', ['environment' => 'staging', 'url' => 'https://staging.example.com/lumberjack/data-sources/']);

        $connection = DataSourceConnection::query()->where('environment', 'staging')->sole();
        expect($this->toolResult($response)['uuid'])->toBe($connection->uuid)
            ->and($connection->url)->toBe('https://staging.example.com/lumberjack/data-sources')
            ->and($response->getContent())->not->toContain((string) $connection->pull_token);
    });

    it('changes the time zone and keeps the address', function () {
        $this->callTool('lj_mcp_secret', 'update_data_source_connection', ['connection' => $this->connection->uuid, 'timezone' => 'Europe/Copenhagen'])->assertOk();

        expect($this->connection->refresh())->timezone->toBe('Europe/Copenhagen')
            ->url->toBe('https://app.example.com/lumberjack/data-sources');
    });

    it('keeps a connection on its host, so the pull token never follows a new address', function () {
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'update_data_source_connection', ['connection' => $this->connection->uuid, 'url' => 'https://attacker.example/lumberjack/data-sources'])))->toBeTrue()
            ->and($this->connection->refresh()->url)->toBe('https://app.example.com/lumberjack/data-sources');

        $this->callTool('lj_mcp_secret', 'update_data_source_connection', ['connection' => $this->connection->uuid, 'url' => 'https://app.example.com/v2/data-sources'])->assertOk();
        expect($this->connection->refresh()->url)->toBe('https://app.example.com/v2/data-sources');
    });

    it('disconnects an app with its sources', function () {
        DataSource::factory()->for($this->project)->connected()->create();

        $this->callTool('lj_mcp_secret', 'delete_data_source_connection', ['connection' => $this->connection->uuid])->assertOk();

        expect(DataSourceConnection::query()->count())->toBe(0)
            ->and(DataSource::query()->pluck('id')->all())->toBe([$this->source->id]);
    });
});

describe('what it refuses', function () {
    it('refuses a token below the tool\'s level', function (string $tool, McpLevel $level, array $arguments) {
        ($this->limitTo)($level);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, ($this->arguments)($arguments))))->toBeTrue()
            ->and($this->source->refresh()->name)->toBe('Orders API')
            ->and(DataSourceConnection::query()->count())->toBe(1);
    })->with([
        'create_data_source at read' => ['create_data_source', McpLevel::Read, ['name' => 'Stats', 'url' => 'https://example.com/a.json', 'format' => 'json', 'interval_minutes' => 1, 'timeout_s' => 5]],
        'update_data_source at read' => ['update_data_source', McpLevel::Read, ['data_source' => 'source', 'name' => 'Renamed']],
        'delete_data_source at write' => ['delete_data_source', McpLevel::Write, ['data_source' => 'source']],
        'create_data_source_connection at write' => ['create_data_source_connection', McpLevel::Write, ['environment' => 'staging', 'url' => 'https://staging.example.com/x']],
        'delete_data_source_connection at write' => ['delete_data_source_connection', McpLevel::Write, ['connection' => 'connection']],
    ]);

    it('refuses what the token\'s owner may not change', function (string $tool, array $arguments) {
        Queue::fake();
        ($this->denyUpdates)();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, ($this->arguments)($arguments))))->toBeTrue()
            ->and($this->source->refresh()->name)->toBe('Orders API')
            ->and($this->connection->refresh()->timezone)->toBe('UTC')
            ->and(DataSourceConnection::query()->count())->toBe(1);
        Queue::assertNothingPushed();
    })->with([
        'create_data_source' => ['create_data_source', ['name' => 'Stats', 'url' => 'https://example.com/a.json', 'format' => 'json', 'interval_minutes' => 1, 'timeout_s' => 5]],
        'update_data_source' => ['update_data_source', ['data_source' => 'source', 'name' => 'Renamed']],
        'fetch_data_source' => ['fetch_data_source', ['data_source' => 'source']],
        'delete_data_source' => ['delete_data_source', ['data_source' => 'source']],
        'create_data_source_connection' => ['create_data_source_connection', ['environment' => 'staging', 'url' => 'https://staging.example.com/x']],
        'update_data_source_connection' => ['update_data_source_connection', ['connection' => 'connection', 'timezone' => 'Europe/Copenhagen']],
        'delete_data_source_connection' => ['delete_data_source_connection', ['connection' => 'connection']],
    ]);
});
