<?php

declare(strict_types=1);

use App\Domains\Notifications\Support\VapidKeys;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

covers(VapidKeys::class);

describe('the VAPID keys', function () {
    beforeEach(fn () => config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]));

    it('makes a pair once when the environment sets none, stores it encrypted and reuses it', function () {
        app(VapidKeys::class)->ensure();
        $public = config('webpush.vapid.public_key');
        $stored = DB::table('app_secrets')->where('name', VapidKeys::SECRET)->value('value');

        expect($public)->toBeString()->not->toBeEmpty()
            ->and($stored)->not->toContain($public)
            ->and(Crypt::decrypt($stored)['publicKey'])->toBe($public);

        // The next request or worker starts without them in config.
        app()->forgetScopedInstances();
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        app(VapidKeys::class)->ensure();

        expect(config('webpush.vapid.public_key'))->toBe($public)
            ->and(DB::table('app_secrets')->count())->toBe(1);
    });

    it('uses the keys from the environment and stores nothing', function () {
        config(['webpush.vapid.public_key' => 'from-env-public', 'webpush.vapid.private_key' => 'from-env-private']);

        app(VapidKeys::class)->ensure();

        expect(config('webpush.vapid.public_key'))->toBe('from-env-public')
            ->and(DB::table('app_secrets')->count())->toBe(0);
    });
});
