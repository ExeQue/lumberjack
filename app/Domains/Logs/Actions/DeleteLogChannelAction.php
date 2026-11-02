<?php

declare(strict_types=1);

namespace App\Domains\Logs\Actions;

use App\Domains\Logs\Exceptions\DefaultChannelLockedException;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Shared\Support\ChunkedDeleter;

class DeleteLogChannelAction
{
    public function __construct(
        private readonly ChunkedDeleter $deleter,
    ) {
    }

    public function execute(LogChannel $channel): void
    {
        if ($channel->isDefault()) {
            throw DefaultChannelLockedException::create();
        }

        $this->deleter->delete('log_entries', fn ($query) => $query->where('log_channel_id', $channel->id));
        $channel->delete();
    }
}
