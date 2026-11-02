<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Models\User;

class RecordAdminAuditAction
{
    /**
     * @param  string|null  $subject  what it was done to, as people read it, e.g. a team's name or a user's e-mail
     * @param  array<string, mixed>|null  $data
     */
    public function execute(User $admin, AdminAction $action, ?string $subject = null, ?array $data = null): AdminAuditEntry
    {
        return AdminAuditEntry::query()->create([
            'user_id' => $admin->id,
            'action' => $action,
            'subject' => $subject !== null ? mb_substr($subject, 0, 255) : null,
            'data' => $data,
        ]);
    }
}
