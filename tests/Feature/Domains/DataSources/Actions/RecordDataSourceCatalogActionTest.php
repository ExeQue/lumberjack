<?php

declare(strict_types=1);

use App\Domains\DataSources\Actions\RecordDataSourceCatalogAction;
use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Projects\Models\Project;

covers(RecordDataSourceCatalogAction::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->connection = DataSourceConnection::factory()->for($this->project)->create();
    $this->record = fn (array $sources, array $index = [], ?DataSourceConnection $connection = null) => app(RecordDataSourceCatalogAction::class)->execute($connection ?? $this->connection, ['sources' => $sources, 'complete' => true, ...$index]);
    $this->source = ['key' => 'orders:total', 'label' => 'Orders', 'shape' => 'number'];
});

describe('a list of sources', function () {
    it('creates them as connected ones on the connection', function () {
        ($this->record)([$this->source]);

        $source = DataSource::query()->sole();

        expect($source->format)->toBe(DataSourceFormat::Connected)
            ->and($source->shape)->toBe(DataSourceShape::Number)
            ->and($source->name)->toBe('Orders')
            ->and($source->environment)->toBe('production')
            ->and($source->url)->toBe($this->connection->url)
            ->and($source->data_source_connection_id)->toBe($this->connection->id);
    });

    it('keeps how long the app caches, within the limit', function (mixed $cache, ?int $kept) {
        ($this->record)([[...$this->source, 'cache' => $cache]]);

        expect(DataSource::query()->sole()->cache_seconds)->toBe($kept);
    })->with(['seconds' => [300, 300], 'none' => [null, null], 'too long' => [9_999_999, 86_400], 'not a number' => ['soon', null]]);

    it('updates a source it lists again, without a second row', function () {
        ($this->record)([$this->source]);
        ($this->record)([[...$this->source, 'label' => 'All orders']]);

        expect(DataSource::query()->sole()->name)->toBe('All orders');
    });

    it('archives what it no longer lists on that connection alone, and restores it when listed again', function () {
        $staging = DataSourceConnection::factory()->for($this->project)->create(['environment' => 'staging']);
        ($this->record)([$this->source]);
        ($this->record)([$this->source], connection: $staging);
        ($this->record)([['key' => 'other', 'label' => 'x', 'shape' => 'number']]);

        expect(DataSource::query()->where('environment', 'production')->where('key', 'orders:total')->sole()->archived_at)->not->toBeNull()
            ->and(DataSource::query()->where('environment', 'staging')->sole()->archived_at)->toBeNull();

        ($this->record)([$this->source]);

        expect(DataSource::query()->where('environment', 'production')->where('key', 'orders:total')->sole()->archived_at)->toBeNull();
    });

    it('leaves sources added by hand alone', function () {
        DataSource::factory()->for($this->project)->create();

        ($this->record)([]);

        expect(DataSource::query()->sole()->archived_at)->toBeNull();
    });

    it('forgets what was pulled when the shape changes', function () {
        ($this->record)([$this->source]);
        DataSourceWindow::factory()->forSource(DataSource::query()->sole())->answered(1)->create();

        ($this->record)([[...$this->source, 'shape' => 'series']]);

        expect(DataSourceWindow::query()->count())->toBe(0);
    });
});

describe('what a list cannot do', function () {
    it('skips an entry with a bad key or shape', function (array $bad) {
        ($this->record)([$bad]);

        expect(DataSource::query()->count())->toBe(0);
    })->with([
        'a key with spaces' => [['key' => 'a b', 'label' => 'x', 'shape' => 'number']],
        'an unknown shape' => [['key' => 'a', 'label' => 'x', 'shape' => 'pie']],
        'no key' => [['key' => null]],
    ]);

    it('archives nothing from a list it cannot trust', function (array $listed, array $index) {
        ($this->record)([$this->source, ['key' => 'other', 'label' => 'x', 'shape' => 'number']]);

        ($this->record)($listed, $index);

        expect(DataSource::query()->whereNotArchived()->count())->toBe(2);
    })->with([
        'a bad entry' => [[['key' => 'orders:total', 'shape' => 'number'], ['key' => 'B B', 'shape' => 'number']], []],
        'the app could not make a source' => [[], ['complete' => false]],
        'no word on whether it is whole' => [[], ['complete' => null]],
        'not a list' => [[], ['sources' => 'nope']],
        'an empty list' => [[], []],
    ]);

    it('counts what is archived towards the limit, and forgets it after a while', function () {
        ($this->record)([$this->source]);
        DataSource::query()->update(['archived_at' => now()->subDays(RecordDataSourceCatalogAction::KEEP_ARCHIVED_DAYS + 1)]);

        ($this->record)([['key' => 'other', 'label' => 'x', 'shape' => 'number']]);

        expect(DataSource::query()->sole()->key)->toBe('other');
    });

    it('takes a key listed twice once, without a NUL in its name', function () {
        ($this->record)([[...$this->source, 'label' => "Or\0ders"], $this->source]);

        expect(DataSource::query()->sole()->name)->toBe('Orders');
    });
});

it('keeps a long archived source that is listed again', function () {
    ($this->record)([$this->source]);
    $source = DataSource::query()->sole();
    $source->update(['archived_at' => now()->subDays(40)]);

    ($this->record)([$this->source]);

    expect(DataSource::query()->sole())->uuid->toBe($source->uuid)->archived_at->toBeNull();
});
