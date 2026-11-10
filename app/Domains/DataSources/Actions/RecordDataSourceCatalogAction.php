<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Health\Exceptions\ProbeFailedException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * What an app answered when asked which sources it has. One it no longer lists is archived,
 * and comes back if it returns, but only on a list that can be trusted: whole, by the app's
 * own word, not empty, and with nothing in it refused.
 */
class RecordDataSourceCatalogAction
{
    /** The most sources one app can list. */
    public const int MAX_SOURCES = 200;

    /** The most connected sources a project can hold, over all its connections. */
    public const int MAX_PER_PROJECT = 1000;

    /** How long a source no longer offered waits to come back. */
    public const int KEEP_ARCHIVED_DAYS = 30;

    private const int MAX_GROUP_DEPTH = 3;

    private const int MAX_GROUP_NAME = 40;

    private const string KEY_PATTERN = '/^[a-z0-9][a-z0-9:._-]{0,99}$/D';

    /**
     * @param  array<mixed>  $index
     *
     * @throws ProbeFailedException when the app offers more than fits
     */
    public function execute(DataSourceConnection $connection, array $index): void
    {
        $project = $connection->project;
        $reported = $index['sources'] ?? null;
        $listed = is_array($reported) ? array_slice(array_values($reported), 0, self::MAX_SOURCES) : [];
        $existing = $connection->sources()->get()->keyBy('key');
        $total = $project->dataSources()->whereConnected()->count();
        $allAccepted = true;
        $overLimit = is_array($reported) && count($reported) > self::MAX_SOURCES;
        $seen = [];

        foreach ($listed as $source) {
            $key = is_array($source) && is_string($source['key'] ?? null) ? $source['key'] : '';
            $shape = is_array($source) && is_string($source['shape'] ?? null) ? DataSourceShape::tryFrom($source['shape']) : null;

            if ($shape === null || preg_match(self::KEY_PATTERN, $key) !== 1 || in_array($key, $seen, true)) {
                $allAccepted = false;

                continue;
            }

            /** @var DataSource|null $row */
            $row = $existing->get($key);

            if ($row === null && $total >= self::MAX_PER_PROJECT) {
                $overLimit = true;

                continue;
            }

            $total += $row === null ? 1 : 0;
            $row ??= new DataSource(['project_id' => $project->id, 'data_source_connection_id' => $connection->id, 'environment' => $connection->environment, 'key' => $key, 'format' => DataSourceFormat::Connected, 'interval_minutes' => FetchDataSourceWindowAction::EVERY_MINUTES, 'timeout_s' => SendSignedPullAction::TIMEOUT_S]);
            $row->fill([
                'name' => mb_substr(str_replace("\0", '', is_string($source['label'] ?? null) && $source['label'] !== '' ? $source['label'] : $key), 0, 150),
                'group_path' => $this->groupPath($source['group'] ?? null),
                'url' => $connection->url,
                'shape' => $shape,
                'cache_seconds' => is_int($source['cache'] ?? null) && $source['cache'] > 0 ? min($source['cache'], FetchDataSourceWindowAction::MAX_STEP_SECONDS) : null,
                'archived_at' => null,
                // Only a connector Lumberjack reads itself narrows a source; an app's answer is its own.
                'filter_value' => $connection->isUmami() && is_string($source['filter'] ?? null) ? mb_substr($source['filter'], 0, 500) : null,
            ]);

            // What was pulled in the old form cannot be read as the new one.
            if ($row->exists && $row->isDirty('shape')) {
                DataSourceWindow::query()->where('data_source_id', $row->id)->delete();
            }

            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                // A second sync got here first; the next round updates it.
            }

            $seen[] = $key;
        }

        if (($index['complete'] ?? false) === true && $allAccepted && is_array($reported) && $reported !== [] && count($reported) <= self::MAX_SOURCES) {
            $connection->sources()->whereNotArchived()
                ->whereNotIn('key', $seen)
                ->update(['archived_at' => now()]);
        }

        // After the list is read, as a source that is back is no longer archived.
        $connection->sources()->where('archived_at', '<', now()->subDays(self::KEEP_ARCHIVED_DAYS))->delete();

        // Everything that fits is kept; the connection says what did not.
        if ($overLimit) {
            throw new ProbeFailedException(__('data_sources.errors.too_many_sources', ['listed' => self::MAX_SOURCES, 'held' => self::MAX_PER_PROJECT]));
        }
    }

    /** The path an app files a source under, as one label; older apps send none. */
    private function groupPath(mixed $group): ?string
    {
        $names = array_filter(
            array_map($this->groupName(...), is_array($group) ? array_values($group) : []),
            fn (string $name): bool => $name !== '',
        );

        return $names === [] ? null : implode(' > ', array_slice($names, 0, self::MAX_GROUP_DEPTH));
    }

    /** One level of a path: visible text only, and no separator of its own. */
    private function groupName(mixed $name): string
    {
        return is_string($name) ? mb_substr(trim((string) preg_replace('/[\p{C}\p{Z}>]+/u', ' ', $name)), 0, self::MAX_GROUP_NAME) : '';
    }
}
