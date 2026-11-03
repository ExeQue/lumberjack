<?php

declare(strict_types=1);

namespace App\Domains\Screens\Models;

use App\Domains\Screens\Eloquent\Builders\TvPairingBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\TvPairingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The code a TV shows until someone signed in confirms it: single use, short lived, and
 * bound to the TV's pairing cookie (of which only the sha256 is stored), so the code
 * alone gets no other browser anywhere. The uuid names its broadcast channel; the code
 * never appears in one.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code six characters of CODE_ALPHABET
 * @property string $secret_hash
 * @property string|null $user_agent
 * @property string|null $ip
 * @property CarbonImmutable $expires_at
 * @property int|null $display_id
 * @property CarbonImmutable|null $confirmed_at
 * @property int $slot the window of its computer that asks, /tv/{slot}
 * @property string|null $device_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'secret_hash', 'user_agent', 'ip', 'expires_at', 'display_id', 'confirmed_at', 'slot', 'device_hash'])]
#[Hidden(['secret_hash', 'device_hash'])]
#[UseEloquentBuilder(TvPairingBuilder::class)]
#[UseFactory(TvPairingFactory::class)]
class TvPairing extends Model
{
    /** @use HasFactory<TvPairingFactory> */
    use HasFactory;
    use HasUuids;

    /** No 0/O and 1/I, so a code read off a TV across the room is never ambiguous. */
    public const string CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const int CODE_LENGTH = 6;

    public const int LIFETIME_MINUTES = 10;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'slot' => 'integer',
        ];
    }

    /** @return BelongsTo<Display, $this> */
    public function display(): BelongsTo
    {
        return $this->belongsTo(Display::class);
    }

    /** The device of the team this browser already is, by its device cookie. */
    public function existingDevice(int $teamId): ?Device
    {
        return $this->device_hash === null ? null : Device::query()->whereTeam($teamId)->whereDeviceHash($this->device_hash)->first();
    }

    public function isPending(): bool
    {
        return $this->confirmed_at === null && $this->expires_at->isFuture();
    }

    /** K7QM2X as K7Q-M2X, the way the TV shows it. */
    public function formattedCode(): string
    {
        return substr($this->code, 0, 3).'-'.substr($this->code, 3);
    }

    /** What someone typed (k7q-m2x, "K7Q M2X") as a stored code. */
    public static function normalizeCode(string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }
}
