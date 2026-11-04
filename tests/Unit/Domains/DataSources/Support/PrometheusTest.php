<?php

declare(strict_types=1);

use App\Domains\DataSources\Support\Prometheus;

covers(Prometheus::class);

beforeEach(function () {
    $this->text = <<<'TEXT'
        # HELP http_requests_total Requests served.
        # TYPE http_requests_total counter
        http_requests_total{method="GET",status="200",route="/"} 100 1700000000000
        http_requests_total{method="GET",status="500",route="/"} 4
        http_requests_total{method="POST",status="200",route="/cart"} 30
        # TYPE queue_depth gauge
        queue_depth{queue="default"} 7
        queue_depth{queue="mail"} NaN
        queue_depth{queue="slow"} +Inf
        # TYPE http_request_duration_seconds histogram
        http_request_duration_seconds_bucket{le="0.5"} 80
        http_request_duration_seconds_bucket{le="+Inf"} 100
        http_request_duration_seconds_sum 41.5
        http_request_duration_seconds_count 100
        http_request_duration_seconds_created 1700000000
        # just a comment
        build_info{version="1.2",path="C:\\dir",quote="say \"hi\"",text="a\nb"} 1
        TEXT;
    $this->parsed = Prometheus::parse($this->text);
    $this->read = fn (string $selector, ?array $previous = null, ?float $seconds = null): ?array => Prometheus::evaluate($this->parsed, $previous, $seconds, $selector);
});

describe('parsing the text format', function () {
    it('keeps the samples by name with their family type, the timestamp left out', function () {
        expect($this->parsed['families']['http_requests_total'])->toBe(['type' => 'counter', 'samples' => [
            ['labels' => ['method' => 'GET', 'status' => '200', 'route' => '/'], 'value' => 100.0],
            ['labels' => ['method' => 'GET', 'status' => '500', 'route' => '/'], 'value' => 4.0],
            ['labels' => ['method' => 'POST', 'status' => '200', 'route' => '/cart'], 'value' => 30.0],
        ]])
            ->and($this->parsed['families']['http_request_duration_seconds_bucket']['type'])->toBe('histogram')
            ->and($this->parsed['families']['http_request_duration_seconds_sum']['samples'][0]['value'])->toBe(41.5)
            ->and($this->parsed['families']['build_info']['type'])->toBe('untyped');
    });

    it('unescapes label values', function () {
        expect($this->parsed['families']['build_info']['samples'][0]['labels'])->toBe(['version' => '1.2', 'path' => 'C:\\dir', 'quote' => 'say "hi"', 'text' => "a\nb"]);
    });

    it('leaves out NaN and infinite values, creation times and comments', function () {
        expect(array_column($this->parsed['families']['queue_depth']['samples'], 'labels'))->toBe([['queue' => 'default']])
            ->and($this->parsed['families'])->not->toHaveKey('http_request_duration_seconds_created')
            ->and(Prometheus::count($this->parsed))->toBe(9);
    });

    it('reads OpenMetrics leniently: family names without _total, exemplars and # EOF', function () {
        $parsed = Prometheus::parse("# TYPE jobs counter\njobs_total{queue=\"a\"} 5 # {trace_id=\"x\"} 1 1700000000.5\njobs_created{queue=\"a\"} 1700000000\n# EOF\njobs_total{queue=\"b\"} 9\n");

        expect($parsed['families'])->toBe(['jobs_total' => ['type' => 'counter', 'samples' => [['labels' => ['queue' => 'a'], 'value' => 5.0]]]]);
    });

    it('has no samples in what is not the text format', function () {
        expect(Prometheus::count(Prometheus::parse('{"orders": 1}')))->toBe(0)
            ->and(Prometheus::count(Prometheus::parse("<html>\n<body>hi</body>")))->toBe(0);
    });
});

describe('selectors', function () {
    it('matches with =, !=, =~ and !~, a regex anchored, a missing label as empty', function (string $selector, array $statuses) {
        $gauge = ['families' => ['m' => ['type' => 'gauge', 'samples' => $this->parsed['families']['http_requests_total']['samples']]]];

        expect(array_map(fn (array $r): string => $r['labels']['status'].' '.$r['labels']['route'], Prometheus::evaluate($gauge, null, null, $selector)['rows']))->toBe($statuses);
    })->with([
        'equal' => ['m{status="200"}', ['200 /', '200 /cart']],
        'not equal' => ['m{status!="200"}', ['500 /']],
        'regex' => ['m{route=~"/c.*"}', ['200 /cart']],
        'regex is anchored' => ['m{route=~"c.*"}', []],
        'not regex' => ['m{route!~"/c.*", method=~"GET|POST"}', ['200 /', '500 /']],
        'missing label is empty' => ['m{job=""}', ['200 /', '500 /', '200 /cart']],
        'single quotes and a trailing comma' => ["m{status='500',}", ['500 /']],
    ]);

    it('parses sum and sum by, the by before or after', function () {
        expect(Prometheus::selector('sum by (status, method) (m{a="b"})'))->toBe(['name' => 'm', 'matchers' => [['a', '=', 'b']], 'by' => ['status', 'method']])
            ->and(Prometheus::selector('sum(m) by (status)')['by'])->toBe(['status'])
            ->and(Prometheus::selector('sum(m)')['by'])->toBe([])
            ->and(Prometheus::selector('m')['by'])->toBeNull();
    });

    it('does not parse what is not a selector, and so matches nothing', function (string $selector) {
        expect(Prometheus::selector($selector))->toBeNull()
            ->and(($this->read)($selector))->toBeNull()
            ->and(Prometheus::number(($this->read)($selector)))->toBeNull();
    })->with([
        'empty' => [''],
        'JSONPath' => ['$.orders'],
        'unknown operator' => ['queue_depth{queue=="a"}'],
        'unquoted value' => ['queue_depth{queue=default}'],
        'broken regex' => ['queue_depth{queue=~"("}'],
        'other function' => ['histogram_quantile(0.9, x)'],
        'by twice' => ['sum by (a) (m) by (b)'],
        'too long' => ['m{a="'.str_repeat('x', 600).'"}'],
    ]);
});

describe('reading values', function () {
    it('reads a gauge or an untyped value as it is, all series summed without by', function () {
        expect(Prometheus::number(($this->read)('queue_depth')))->toBe(7.0)
            ->and(Prometheus::number(($this->read)('http_request_duration_seconds_sum')))->toBeNull()
            ->and(Prometheus::number(($this->read)('build_info{version="1.2"}')))->toBe(1.0)
            ->and(Prometheus::number(($this->read)('nothing_here')))->toBeNull();
    });

    it('sums, and with sum by gives one number only for one group', function () {
        $gauge = ['families' => ['m' => ['type' => 'gauge', 'samples' => $this->parsed['families']['http_requests_total']['samples']]]];
        $read = fn (string $selector): ?array => Prometheus::evaluate($gauge, null, null, $selector);

        expect(Prometheus::number($read('m')))->toBe(134.0)
            ->and(Prometheus::number($read('sum(m)')))->toBe(134.0)
            ->and(Prometheus::number($read('sum by (method) (m{method="GET"})')))->toBe(104.0)
            ->and(Prometheus::number($read('sum by (method) (m)')))->toBeNull()
            ->and(Prometheus::rows($read('sum by (method, status) (m)'), 10))->toBe([
                ['label' => 'GET · 200', 'value' => 100.0],
                ['label' => 'GET · 500', 'value' => 4.0],
                ['label' => 'POST · 200', 'value' => 30.0],
            ])
            ->and(Prometheus::rows($read('m{status="500"}'), 10))->toBe([['label' => 'method=GET, status=500, route=/', 'value' => 4.0]])
            ->and(Prometheus::rows($read('sum(m)'), 10))->toBe([['label' => 'm', 'value' => 134.0]]);
    });

    it('reads a counter as its increase per minute since the previous body, a drop as a reset', function () {
        $previous = Prometheus::parse("# TYPE http_requests_total counter\nhttp_requests_total{method=\"GET\",status=\"200\",route=\"/\"} 40\nhttp_requests_total{method=\"GET\",status=\"500\",route=\"/\"} 10\n");

        // 60 more in two minutes is 30 a minute; 10 down to 4 is a reset, so 4 in two minutes.
        $result = ($this->read)('http_requests_total', $previous, 120.0);

        expect($result['counter'])->toBeTrue()
            ->and($result['matched'])->toBe(3)
            ->and(array_column($result['rows'], 'value'))->toBe([30.0, 2.0])
            ->and(Prometheus::number(($this->read)('sum by (status) (http_requests_total{status="200"})', $previous, 120.0)))->toBe(30.0);
    });

    it('has no counter rate without a previous body, and reads histogram counts as counters', function () {
        $result = ($this->read)('http_requests_total');
        $previous = Prometheus::parse("# TYPE http_request_duration_seconds histogram\nhttp_request_duration_seconds_bucket{le=\"0.5\"} 50\n");

        expect($result['rows'])->toBe([])
            ->and($result['matched'])->toBe(3)
            ->and(Prometheus::number(($this->read)('http_request_duration_seconds_bucket{le="0.5"}', $previous, 60.0)))->toBe(30.0)
            ->and(Prometheus::cumulative('http_request_duration_seconds_count', 'histogram'))->toBeTrue()
            ->and(Prometheus::cumulative('rpc_duration_seconds', 'summary'))->toBeFalse()
            ->and(Prometheus::cumulative('queue_depth', 'gauge'))->toBeFalse();
    });
});
