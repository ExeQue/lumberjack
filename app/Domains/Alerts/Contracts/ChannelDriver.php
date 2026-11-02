<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Contracts;

use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Models\NotificationChannel;

/**
 * One alert channel type (mail, Discord, ...). A new type is one class plus its config fields.
 */
interface ChannelDriver
{
    /**
     * Validation rules for the channel's config, keyed by config field.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array;

    public function send(NotificationChannel $channel, IssueAlert $alert): void;

    public function sendTest(NotificationChannel $channel): void;
}
