<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => ChannelType::Discord,
            'name' => '#alarms',
            'config' => ['webhook_url' => 'https://discord.com/api/webhooks/123/abc'],
        ];
    }

    public function mail(): static
    {
        return $this->state(fn () => ['type' => ChannelType::Mail, 'name' => 'drift', 'config' => ['address' => 'ops@example.test']]);
    }
}
