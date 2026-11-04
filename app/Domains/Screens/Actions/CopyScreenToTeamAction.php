<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Data\ScreenImport;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Illuminate\Validation\ValidationException;

class CopyScreenToTeamAction
{
    public function __construct(
        private readonly ExportScreenAction $export,
        private readonly ImportScreenAction $import,
    ) {
    }

    /**
     * A new screen in another team like this one, made the way an export imported there
     * would be: projects and checks found by slug, those the team lacks left out and
     * reported, not shared.
     *
     * @throws ValidationException
     */
    public function execute(Screen $screen, Team $team): ScreenImport
    {
        return $this->import->execute($team, $this->export->execute($screen));
    }
}
