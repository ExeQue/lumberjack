<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;

/**
 * The VAPID key pair that signs browser push, with nothing to configure: keys set in
 * the environment (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY) always win; otherwise a pair is
 * made the first time push is needed and kept in app_secrets, encrypted with APP_KEY,
 * like Reverb's credentials derive from it. Fills config('webpush.vapid') either way.
 * Bound scoped, so it reads the database at most once per request or job.
 */
class VapidKeys
{
    public const string SECRET = 'webpush.vapid';

    private bool $loaded = false;

    public function ensure(): void
    {
        if ($this->loaded || (filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key')))) {
            return;
        }

        $this->loaded = true;
        $pair = $this->stored() ?? $this->generate();

        config(['webpush.vapid.public_key' => $pair['publicKey'], 'webpush.vapid.private_key' => $pair['privateKey']]);
    }

    /**
     * @return array{publicKey: string, privateKey: string}|null
     */
    private function stored(): ?array
    {
        $value = DB::table('app_secrets')->where('name', self::SECRET)->value('value');

        /** @var array{publicKey: string, privateKey: string}|null */
        return is_string($value) ? Crypt::decrypt($value) : null;
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    private function generate(): array
    {
        $made = VAPID::createVapidKeys();
        $keys = ['publicKey' => (string) $made['publicKey'], 'privateKey' => (string) $made['privateKey']];

        // Two processes may get here at once; the first pair stored is the one everyone uses.
        DB::table('app_secrets')->insertOrIgnore([
            'name' => self::SECRET,
            'value' => Crypt::encrypt($keys),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->stored() ?? $keys;
    }
}
