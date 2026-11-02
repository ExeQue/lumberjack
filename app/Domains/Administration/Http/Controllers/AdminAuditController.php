<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Models\AdminAuditEntry;
use Inertia\Inertia;
use Inertia\Response;

class AdminAuditController
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/audit', [
            'entries' => AdminAuditEntry::query()->with('user:id,name,email')->latest('id')->paginate(50)
                ->through(fn (AdminAuditEntry $e): array => [
                    'id' => $e->id,
                    'admin' => $e->user?->name,
                    'action' => $e->action->value,
                    'subject' => $e->subject,
                    'data' => $e->data,
                    'at' => $e->created_at->toIso8601String(),
                ]),
        ]);
    }
}
