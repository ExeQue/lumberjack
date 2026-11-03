<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\AlertSend;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Models\Issue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertSend>
 */
class AlertSendFactory extends Factory
{
    public function definition(): array
    {
        return [
            'alert_rule_id' => AlertRule::factory(),
            'issue_id' => Issue::factory(),
            'notification_channel_id' => NotificationChannel::factory(),
            'status' => 'sent',
            'error' => null,
            'sent_at' => now(),
        ];
    }

    /** The channel refused it. */
    public function failed(string $error): static
    {
        return $this->state(fn () => ['status' => 'failed', 'error' => $error]);
    }
}
