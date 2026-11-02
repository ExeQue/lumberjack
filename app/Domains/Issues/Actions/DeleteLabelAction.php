<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Models\Label;

/**
 * Removes a label from the project and from every issue that had it.
 */
class DeleteLabelAction
{
    public function execute(Label $label): void
    {
        $label->delete();
    }
}
