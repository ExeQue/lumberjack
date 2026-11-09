<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Screens\Exceptions\ScreenConflictException;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\ScreenVersion;
use App\Models\User;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class RestoreScreenVersionAction
{
    public function __construct(
        private readonly SaveScreenAction $save,
    ) {
    }

    /**
     * The screen as it was; what it is now becomes a version in turn.
     *
     * @throws ScreenConflictException
     */
    public function execute(Screen $screen, ScreenVersion $version, User|McpToken|null $by = null, ?int $revision = null): Screen
    {
        if ($version->screen_id !== $screen->id) {
            throw new InvalidArgumentException('That version belongs to another screen.');
        }

        $screen->fill(Arr::only($version->snapshot, Screen::DESIGN));

        return $this->save->execute($screen, $by, $revision, coalesce: false);
    }
}
