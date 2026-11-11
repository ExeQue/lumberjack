<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Notifications\Concerns;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Data\MaintenanceOverrunAlert;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A mail's subject carries the maintenance marker the chat channels show in the headline.
 */
trait MarksMaintenance
{
    protected function markMaintenance(MailMessage $mail, Alert $alert): MailMessage
    {
        // An overrun is about the window itself; the marker would only repeat it.
        if ($alert->maintenance() === null || $alert instanceof MaintenanceOverrunAlert) {
            return $mail;
        }

        $marked = (string) __('alerts.maintenance_marker', ['headline' => (string) $mail->subject]);

        // A subject built from the headline (the generic card) is marked already.
        return str_contains((string) $mail->subject, trim((string) __('alerts.maintenance_marker', ['headline' => '']))) ? $mail : $mail->subject($marked);
    }
}
