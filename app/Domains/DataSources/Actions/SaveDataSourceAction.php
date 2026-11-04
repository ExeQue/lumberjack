<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Projects\Models\Project;

/**
 * Adds or edits a data source. A header given without a value keeps the one it had, so
 * the form never needs to show a secret to save it again.
 */
class SaveDataSourceAction
{
    /**
     * @param  array<string, mixed>  $attributes  validated
     */
    public function execute(Project $project, ?DataSource $source, array $attributes): DataSource
    {
        $source ??= new DataSource(['project_id' => $project->id]);
        $old = array_change_key_case(array_column($source->headers ?? [], 'value', 'name'));
        $headers = [];

        foreach ((array) ($attributes['headers'] ?? []) as $header) {
            $name = trim((string) $header['name']);
            $value = (string) ($header['value'] ?? '');
            $headers[] = ['name' => $name, 'value' => $value !== '' ? $value : (string) ($old[strtolower($name)] ?? '')];
        }

        $source->fill([
            'name' => $attributes['name'],
            // The page shows the URL with its query values masked; sent back as it was shown, it is unchanged.
            'url' => $source->exists && $attributes['url'] === $source->maskedUrl() ? $source->url : $attributes['url'],
            'format' => $attributes['format'],
            'headers' => $headers,
            'interval_minutes' => (int) $attributes['interval_minutes'],
            'timeout_s' => (int) $attributes['timeout_s'],
            // Fetched on the next minute, so a new or changed source shows data at once.
            'next_fetch_at' => null,
        ])->save();

        return $source;
    }
}
