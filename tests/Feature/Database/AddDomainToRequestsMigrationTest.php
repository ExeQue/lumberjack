<?php

declare(strict_types=1);

use App\Domains\Performance\Models\RequestRun;

covers(RequestRun::class);

describe('adding the domain to requests', function () {
    it('backfills each run\'s domain from its URL, lowercased and without credentials', function () {
        $runs = collect([
            'https://Shop.Example.com/orders?page=2',
            'http://user:secret@api.example.com:8080/v1',
            '/relative',
        ])->map(fn (string $url) => RequestRun::factory()->create(['url' => $url, 'domain' => null]));
        $kept = RequestRun::factory()->create(['url' => 'https://other.test/', 'domain' => 'kept.test']);

        (require database_path('migrations/2026_11_09_100000_add_domain_to_requests.php'))->backfill();

        expect($runs->map(fn (RequestRun $r): ?string => $r->refresh()->domain)->all())->toBe(['shop.example.com', 'api.example.com:8080', null])
            ->and($kept->refresh()->domain)->toBe('kept.test');
    });
});
