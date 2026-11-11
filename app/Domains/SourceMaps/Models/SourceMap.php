<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Models;

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Eloquent\Builders\SourceMapBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\SourceMapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded source map of one bundle. The row says what it is; the map is a file on
 * the default disk, too big for the database.
 *
 * @property int $id
 * @property int $project_id
 * @property string $path  the bundle's public path, e.g. /build/assets/app-3f9a.js
 * @property string|null $release
 * @property int $size  in bytes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at  when it was last uploaded
 */
#[Fillable(['project_id', 'path', 'release', 'size'])]
#[UseFactory(SourceMapFactory::class)]
#[UseEloquentBuilder(SourceMapBuilder::class)]
class SourceMap extends Model
{
    /** @use HasFactory<SourceMapFactory> */
    use HasFactory;
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Only the path of a bundle's address counts: /build/assets/app-3f9a.js, whatever the host.
     * Decoded, so a browser's frame and an upload match however each encoded it.
     */
    public static function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $decoded = rawurldecode($path);

        // Only when it decodes to text: %FF alone is no character, and the database takes none.
        return '/'.ltrim(mb_check_encoding($decoded, 'UTF-8') ? $decoded : $path, '/');
    }

    /** Where the map lives on the default disk. */
    public function filePath(): string
    {
        return self::directory($this->project_id).'/'.$this->id.'.map';
    }

    /** Every map of a project, removed as one when the project is deleted. */
    public static function directory(int $projectId): string
    {
        return 'sourcemaps/'.$projectId;
    }
}
