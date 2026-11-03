<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AlertSendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $alert_rule_id
 * @property int $issue_id
 * @property int $notification_channel_id
 * @property string $status
 * @property string|null $error
 * @property CarbonImmutable $sent_at
 */
#[Fillable(['alert_rule_id', 'issue_id', 'notification_channel_id', 'status', 'error', 'sent_at'])]
#[WithoutTimestamps]
#[UseFactory(AlertSendFactory::class)]
class AlertSend extends Model
{
    /** @use HasFactory<AlertSendFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
