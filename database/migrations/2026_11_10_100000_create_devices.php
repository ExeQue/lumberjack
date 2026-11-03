<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Device;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Devices: the computer or TV a team paired, told apart by its device cookie, with its
 * windows (/tv/1 to /tv/9) as displays. The displays of one team and device cookie become
 * one device named after its lowest window; the browser, address and cookie hash move
 * there, and a window keeps its own name only where it differs from the device's.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('device_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'device_hash']);
        });

        Schema::table('displays', function (Blueprint $table) {
            $table->foreignIdFor(Device::class)->nullable()->after('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60)->nullable()->change();
        });

        $displays = DB::table('displays')->orderBy('slot')->orderBy('id')->get();
        $groups = $displays->groupBy(fn (object $d): string => $d->team_id.'|'.($d->device_hash ?? 'display-'.$d->id));

        foreach ($groups as $windows) {
            $first = $windows->first();
            $deviceId = DB::table('devices')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'team_id' => $first->team_id,
                'name' => $first->name,
                'device_hash' => $first->device_hash,
                'user_agent' => $windows->pluck('user_agent')->filter()->last(),
                'ip' => $windows->pluck('ip')->filter()->last(),
                'last_seen_at' => $windows->pluck('last_seen_at')->filter()->max(),
                'created_at' => $first->created_at,
                'updated_at' => now(),
            ]);

            foreach ($windows as $window) {
                DB::table('displays')->where('id', $window->id)->update([
                    'device_id' => $deviceId,
                    'name' => $window->name === $first->name ? null : $window->name,
                ]);
            }
        }

        Schema::table('displays', function (Blueprint $table) {
            $table->dropIndex(['device_hash']);
            $table->dropColumn(['device_hash', 'user_agent', 'ip']);
        });

        Schema::table('displays', function (Blueprint $table) {
            $table->foreignIdFor(Device::class)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('displays', function (Blueprint $table) {
            $table->string('device_hash', 64)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip', 45)->nullable();
        });

        foreach (DB::table('devices')->get() as $device) {
            DB::table('displays')->where('device_id', $device->id)->update([
                'device_hash' => $device->device_hash,
                'user_agent' => $device->user_agent,
                'ip' => $device->ip,
            ]);
            DB::table('displays')->where('device_id', $device->id)->whereNull('name')->update(['name' => $device->name]);
        }

        // Nothing kept a name before; a window that never had one goes by its number.
        DB::table('displays')->whereNull('name')->update(['name' => DB::raw("'Window ' || slot")]);

        Schema::table('displays', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Device::class);
            $table->string('name', 60)->nullable(false)->change();
        });

        Schema::dropIfExists('devices');
    }
};
