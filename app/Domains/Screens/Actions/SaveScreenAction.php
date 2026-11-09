<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Exceptions\ScreenConflictException;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\ScreenVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveScreenAction
{
    public const int KEEP_VERSIONS = 20;

    /** Edits save as they happen: one person's or token's close together are one version. */
    private const int SAME_EDIT_MINUTES = 5;

    /**
     * Stores a changed screen as a new revision, keeping what it was, and tells whatever shows it.
     *
     * @param  int|null  $revision  the revision the change was made on; null skips the check
     * @param  bool  $coalesce  false always keeps what the screen is now, as before a restore
     *
     * @throws ScreenConflictException
     */
    public function execute(Screen $screen, User|McpToken|null $by = null, ?int $revision = null, bool $coalesce = true): Screen
    {
        $changed = DB::transaction(function () use ($screen, $by, $revision, $coalesce): bool {
            $current = Screen::query()->whereKey($screen->id)->lockForUpdate()->firstOrFail();

            if ($revision !== null && $revision !== $current->revision) {
                throw ScreenConflictException::on($screen, $revision, $current->revision);
            }

            if (! $screen->isDirty()) {
                return false;
            }

            $this->remember($current, $by, $coalesce);
            $screen->revision = $current->revision + 1;
            $screen->save();

            return true;
        });

        if ($changed) {
            // Saved is saved: a broadcaster that is down must not report it as failed.
            rescue(fn () => ScreenChanged::dispatch($screen));
        }

        return $screen;
    }

    private function remember(Screen $screen, User|McpToken|null $by, bool $coalesce): void
    {
        $latest = ScreenVersion::query()->whereBelongsTo($screen)->latest('id')->first();

        $sameAuthor = $latest !== null && (($by instanceof User && $latest->user_id === $by->id) || ($by instanceof McpToken && $latest->mcp_token_id === $by->id));

        if ($coalesce && $sameAuthor && $latest->created_at->gte(now()->subMinutes(self::SAME_EDIT_MINUTES))) {
            return;
        }

        ScreenVersion::query()->create([
            'screen_id' => $screen->id,
            'revision' => $screen->revision,
            'snapshot' => $screen->only(Screen::DESIGN),
            'user_id' => $by instanceof User ? $by->id : null,
            'mcp_token_id' => $by instanceof McpToken ? $by->id : null,
            'author' => $by?->name,
            'created_at' => now(),
        ]);

        $oldestKept = ScreenVersion::query()->whereBelongsTo($screen)->latest('id')->skip(self::KEEP_VERSIONS - 1)->value('id');

        if ($oldestKept !== null) {
            ScreenVersion::query()->whereBelongsTo($screen)->where('id', '<', $oldestKept)->delete();
        }
    }
}
