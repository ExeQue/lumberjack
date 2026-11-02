<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Issues\Exceptions\LabelNameTakenException;
use App\Domains\Issues\Models\Label;

class UpdateLabelAction
{
    public function execute(Label $label, string $name, LabelColor $color): Label
    {
        $name = mb_substr(trim($name), 0, Label::MAX_NAME);

        $taken = Label::query()
            ->where('project_id', $label->project_id)
            ->whereKeyNot($label->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($taken) {
            throw LabelNameTakenException::named($name);
        }

        $label->update(['name' => $name, 'color' => $color]);

        return $label;
    }
}
