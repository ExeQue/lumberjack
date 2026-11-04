<?php

declare(strict_types=1);

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Support\JsonPath;
use App\Domains\DataSources\Support\Prometheus;
use App\Domains\Screens\Actions\PreviewDataSourceAction;
use App\Domains\Screens\Http\Controllers\DataSourcePreviewController;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Teams\CreatesTeamMembers;

covers(DataSourcePreviewController::class, PreviewDataSourceAction::class, JsonPath::class, Prometheus::class);

uses(CreatesTeamMembers::class);

beforeEach(function () {
    ['user' => $this->user, 'project' => $this->project] = $this->memberWithProject(TeamRole::Member);
    $this->screen = Screen::factory()->for($this->project->team)->create();
    $this->source = DataSource::factory()->for($this->project)->create([
        'url' => 'https://example.com/stats.json?key=topsecret',
        'headers' => [['name' => 'Authorization', 'value' => 'Bearer hunter2']],
        'last_ok_at' => now(),
        'last_body' => json_encode([
            'orders' => 30,
            'rate' => '1.5',
            'some key' => 7,
            'note' => 'hi',
            'services' => [['name' => 'api', 'ms' => 120, 'up' => true], ['name' => 'pay', 'ms' => 'slow', 'up' => false]],
        ]),
    ]);
    $this->preview = fn (array $query = [], ?Screen $screen = null, ?DataSource $source = null) => $this->actingAs($this->user)
        ->getJson(route('screens.data-sources.preview', [$screen ?? $this->screen, $source ?? $this->source, ...$query]));
});

describe('who may ask', function () {
    it('refuses someone outside the screen\'s team', function () {
        $this->user = User::factory()->create();

        ($this->preview)(['type' => 'stat'])->assertForbidden();
    });

    it('does not find a data source of another team, even on the user\'s own screen', function () {
        $other = DataSource::factory()->create(['last_body' => '{"orders": 1}']);

        ($this->preview)(['type' => 'stat'], source: $other)->assertNotFound();
    });

    it('refuses another team\'s screen, even with a source of the user\'s team', function () {
        ($this->preview)(['type' => 'stat'], Screen::factory()->create())->assertForbidden();
    });

    it('refuses an unknown type', function () {
        ($this->preview)(['type' => 'health'])->assertUnprocessable();
    });
});

describe('the latest response', function () {
    it('never fetches, and never shows the headers or the URL', function () {
        Http::fake();

        $response = ($this->preview)(['type' => 'stat', 'path' => '$.orders'])->assertOk();

        Http::assertNothingSent();
        expect($response->getContent())->not->toContain('hunter2')->not->toContain('Authorization')->not->toContain('topsecret')
            ->and($response->json('fetched_at'))->toBe($this->source->last_ok_at->toIso8601String())
            ->and($response->json('body'))->toContain("\n    \"orders\": 30,")
            ->and($response->json('truncated'))->toBeFalse();
    });

    it('cuts a large body short', function () {
        $this->source->update(['last_body' => json_encode(['rows' => array_fill(0, 2000, 'xxxxxxxxxxxxxxxx')])]);

        $response = ($this->preview)(['type' => 'stat'])->assertOk();

        expect(strlen($response->json('body')))->toBe(PreviewDataSourceAction::BODY_BYTES)
            ->and($response->json('truncated'))->toBeTrue();
    });

    it('is empty before the first good fetch', function () {
        $this->source->update(['last_body' => null, 'last_ok_at' => null]);

        ($this->preview)(['type' => 'stat', 'path' => '$.orders'])->assertOk()->assertExactJson([
            'fetched_at' => null, 'body' => null, 'truncated' => false, 'suggestions' => [], 'item_suggestions' => [], 'result' => null,
        ]);
    });
});

describe('suggestions', function () {
    it('offers the numbers for a stat, gauge or chart, numeric strings too', function (string $type) {
        expect(($this->preview)(['type' => $type])->json('suggestions'))->toBe([
            ['path' => '$.orders', 'sample' => 30, 'kind' => 'number'],
            ['path' => '$.rate', 'sample' => '1.5', 'kind' => 'number'],
            ['path' => "$['some key']", 'sample' => 7, 'kind' => 'number'],
            ['path' => '$.services[0].ms', 'sample' => 120, 'kind' => 'number'],
        ]);
    })->with(['stat', 'gauge', 'chart']);

    it('offers the arrays for a list, and the fields of its items', function () {
        $response = ($this->preview)(['type' => 'list', 'path' => '$.services']);

        expect($response->json('suggestions'))->toBe([
            ['path' => '$.services', 'sample' => 2, 'kind' => 'array'],
            ['path' => '$.services[*].name', 'sample' => 2, 'kind' => 'array'],
            ['path' => '$.services[*].ms', 'sample' => 2, 'kind' => 'array'],
            ['path' => '$.services[*].up', 'sample' => 2, 'kind' => 'array'],
        ])->and($response->json('item_suggestions'))->toBe([
            ['path' => '$.name', 'sample' => 'api', 'kind' => 'text'],
            ['path' => '$.ms', 'sample' => 120, 'kind' => 'number'],
            ['path' => '$.up', 'sample' => true, 'kind' => 'text'],
        ]);
    });

    it('offers a number in every item of an array, not only the first', function () {
        $this->source->update(['last_body' => json_encode(['warehouses' => array_map(fn (int $i): array => ['name' => "w{$i}", 'stock' => $i * 10], range(1, 4))])]);

        expect(array_column(($this->preview)(['type' => 'stat'])->json('suggestions'), 'path'))->toBe([
            '$.warehouses[0].stock', '$.warehouses[1].stock', '$.warehouses[2].stock', '$.warehouses[3].stock',
        ]);
    });

    it('stops at the depth and count limits', function () {
        $deep = 1;
        foreach (range(1, 12) as $_) {
            $deep = ['a' => $deep];
        }
        $this->source->update(['last_body' => json_encode(['deep' => $deep, 'many' => array_combine(array_map(fn (int $i): string => "k{$i}", range(1, 300)), range(1, 300))])]);

        $suggestions = ($this->preview)(['type' => 'stat'])->json('suggestions');

        expect($suggestions)->toHaveCount(PreviewDataSourceAction::MAX_SUGGESTIONS)
            ->and($suggestions[0]['path'])->toBe('$.many.k1');
    });
});

describe('the result', function () {
    it('is the one number a stat reads, also at a suggested quoted key', function () {
        expect(($this->preview)(['type' => 'stat', 'path' => '$.rate'])->json('result'))->toBe(['value' => 1.5, 'count' => 1, 'reason' => null])
            ->and(($this->preview)(['type' => 'stat', 'path' => "$['some key']"])->json('result.value'))->toEqual(7);
    });

    it('says why there is no number', function (string $path, int $count, string $reason) {
        expect(($this->preview)(['type' => 'gauge', 'path' => $path])->json('result'))->toBe(['value' => null, 'count' => $count, 'reason' => $reason]);
    })->with([
        'no match' => ['$.nope', 0, 'no_match'],
        'several' => ['$.services[*].name', 2, 'several'],
        'not a number' => ['$.note', 1, 'not_number'],
    ]);

    it('reads the first rows of a list as the widget does', function () {
        expect(($this->preview)(['type' => 'list', 'path' => '$.services', 'label_path' => '$.name', 'value_path' => 'ms'])->json('result'))->toBe([
            'count' => 2,
            'reason' => null,
            'rows' => [['label' => 'api', 'value' => 120], ['label' => 'pay', 'value' => 'slow']],
        ]);
    });

    it('reads a suggested field across a list as its items', function () {
        expect(($this->preview)(['type' => 'list', 'path' => '$.services[*].name'])->json('result'))->toBe([
            'count' => 2,
            'reason' => null,
            'rows' => [['label' => 'api', 'value' => null], ['label' => 'pay', 'value' => null]],
        ]);
    });

    it('says when a list matches nothing', function () {
        expect(($this->preview)(['type' => 'list', 'path' => '$.nope'])->json('result'))->toBe(['count' => 0, 'reason' => 'no_match', 'rows' => []]);
    });
});

describe('a Prometheus source', function () {
    beforeEach(function () {
        $text = fn (int $ok): string => "# TYPE http_requests_total counter\nhttp_requests_total{method=\"GET\",status=\"200\"} {$ok}\nhttp_requests_total{method=\"GET\",status=\"500\"} 4\n# TYPE queue_depth gauge\nqueue_depth{queue=\"a\"} 5\nqueue_depth{queue=\"b\"} 2\n";
        $this->source->update(['format' => DataSourceFormat::Prometheus, 'last_body' => $text(160)]);
        DB::table('data_source_fetches')->insert(['project_id' => $this->project->id, 'data_source_id' => $this->source->id, 'body' => json_encode(Prometheus::parse($text(100))), 'fetched_at' => now()->subMinutes(2)]);
    });

    it('shows the raw text and offers the metrics, then a selector per label value', function () {
        $response = ($this->preview)(['type' => 'stat'])->assertOk();

        expect($response->json('body'))->toStartWith('# TYPE http_requests_total counter')
            ->and($response->json('suggestions'))->toBe([
                ['path' => 'http_requests_total', 'sample' => 2, 'kind' => 'family', 'type' => 'counter'],
                ['path' => 'queue_depth', 'sample' => 2, 'kind' => 'family', 'type' => 'gauge'],
                ['path' => 'http_requests_total{method="GET"}', 'sample' => 2, 'kind' => 'selector', 'type' => 'counter'],
                ['path' => 'http_requests_total{status="200"}', 'sample' => 1, 'kind' => 'selector', 'type' => 'counter'],
                ['path' => 'http_requests_total{status="500"}', 'sample' => 1, 'kind' => 'selector', 'type' => 'counter'],
                ['path' => 'queue_depth{queue="a"}', 'sample' => 1, 'kind' => 'selector', 'type' => 'gauge'],
                ['path' => 'queue_depth{queue="b"}', 'sample' => 1, 'kind' => 'selector', 'type' => 'gauge'],
            ]);
    });

    it('offers a sum by per label for a list', function () {
        expect(array_column(($this->preview)(['type' => 'list'])->json('suggestions'), 'path'))
            ->toContain('sum by (status) (http_requests_total)', 'sum by (queue) (queue_depth)')
            ->not->toContain('sum by (method) (http_requests_total)');
    });

    it('reads a counter per minute and a gauge as it is', function () {
        expect(($this->preview)(['type' => 'stat', 'path' => 'http_requests_total{status="200"}'])->json('result'))->toBe(['value' => 30, 'count' => 1, 'reason' => null, 'counter' => true])
            ->and(($this->preview)(['type' => 'gauge', 'path' => 'queue_depth'])->json('result'))->toBe(['value' => 7, 'count' => 2, 'reason' => null, 'counter' => false]);
    });

    it('says why there is no number', function (string $path, string $reason) {
        expect(($this->preview)(['type' => 'stat', 'path' => $path])->json('result.reason'))->toBe($reason);
    })->with([
        'not a selector' => ['$.orders', 'invalid'],
        'nothing' => ['queue_depth{queue="c"}', 'no_match'],
        'several groups' => ['sum by (queue) (queue_depth)', 'several'],
    ]);

    it('says a counter needs a second fetch', function () {
        DB::table('data_source_fetches')->delete();

        expect(($this->preview)(['type' => 'stat', 'path' => 'http_requests_total'])->json('result.reason'))->toBe('no_rate');
    });

    it('reads the rows of a list', function () {
        expect(($this->preview)(['type' => 'list', 'path' => 'sum by (status) (http_requests_total)'])->json('result'))->toBe([
            'count' => 2, 'reason' => null, 'counter' => true, 'rows' => [['label' => '200', 'value' => 30], ['label' => '500', 'value' => 0]],
        ]);
    });
});
