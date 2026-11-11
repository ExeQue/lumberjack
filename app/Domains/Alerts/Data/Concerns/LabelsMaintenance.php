<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data\Concerns;

use App\Domains\Maintenance\Models\MaintenanceWindow;

/**
 * Looks up the window an alert is sent during, once per alert, and marks its headline
 * with it, so every channel that shows the headline shows the marker too.
 */
trait LabelsMaintenance
{
    public function maintenance(): ?MaintenanceWindow
    {
        return once(fn (): ?MaintenanceWindow => MaintenanceWindow::openFor(...$this->maintenanceScope()));
    }

    /**
     * The project id and environment the alert is about; a null environment is covered by any window.
     *
     * @return array{int, ?string}
     */
    abstract protected function maintenanceScope(): array;

    protected function marked(string $headline): string
    {
        return $this->maintenance() !== null ? (string) __('alerts.maintenance_marker', ['headline' => $headline]) : $headline;
    }
}
