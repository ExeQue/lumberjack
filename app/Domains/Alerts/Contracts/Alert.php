<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Contracts;

use App\Domains\Maintenance\Models\MaintenanceWindow;

/**
 * What every channel can send: an issue alert, or one about the project itself such as
 * a queue backlog. Chat and push channels format these parts their own way.
 */
interface Alert
{
    public function headline(): string;

    /**
     * @return array{title: string, subject: string, body: list<string>}
     */
    public function summary(): array;

    /**
     * Label => value, listed under the text.
     *
     * @return array<string, string>
     */
    public function facts(): array;

    /** "high", "normal" or "low". */
    public function urgency(): string;

    public function url(): string;

    /**
     * The maintenance window it was sent during, if any: chat and mail mark the message,
     * webhooks carry it as their maintenance object.
     */
    public function maintenance(): ?MaintenanceWindow;
}
