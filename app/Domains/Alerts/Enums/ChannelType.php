<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Enums;

enum ChannelType: string
{
    case Mail = 'mail';
    case Discord = 'discord';
    case Webhook = 'webhook';

    public function label(): string
    {
        return __('alerts.channel_types.'.$this->value);
    }
}
