<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Actions;

use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\LruCache;
use App\Domains\SourceMaps\Models\SourceMap;
use App\Domains\SourceMaps\Support\SourceMapDecoder;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns a browser error's minified frames back into the original source, before it is
 * grouped, so an issue stays one issue across builds. Each frame keeps its minified
 * position. A frame without a map, or with a broken one, stays as it came.
 */
class SymbolicateFramesAction
{
    /** Lines of source shown around a frame, like the server SDK sends. */
    public const int CODE_LINES = 15;

    /** Maps one error is mapped with, its top frames' first: any error's frames could name every bundle. */
    public const int MAX_MAPS_PER_ERROR = 4;

    /**
     * Maps decoded per instance, which is one ingest batch: a map can be 20 MB, and the
     * browser key that sends errors is public. Past it frames stay minified.
     */
    public const int MAX_DECODES = 20;

    /**
     * Decoded maps by id and upload time. A map can be 20 MB, so only a few.
     *
     * @var LruCache<SourceMapDecoder|null>
     */
    private LruCache $decoded;

    private int $decodes = 0;

    public function __construct()
    {
        $this->decoded = new LruCache(self::MAX_MAPS_PER_ERROR);
    }

    public function execute(Project $project, IssueSignal $signal): IssueSignal
    {
        try {
            return $this->symbolicate($project, $signal);
        } catch (Throwable $e) {
            report($e);

            return $signal;
        }
    }

    private function symbolicate(Project $project, IssueSignal $signal): IssueSignal
    {
        $paths = collect($signal->stacktrace)->map(fn (array $frame): ?string => $this->bundlePath($frame))->filter()->unique()->take(self::MAX_MAPS_PER_ERROR)->values()->all();

        if ($paths === []) {
            return $signal;
        }

        // The path is unique on its own with a hash in the file name; the release breaks a tie, then the newest upload.
        $maps = SourceMap::query()->whereProject($project)->wherePathIn($paths)->get()
            ->sortBy(fn (SourceMap $map): array => [(int) ($map->release === $signal->release), $map->updated_at->getTimestamp(), $map->id])
            ->keyBy('path');

        if ($maps->isEmpty()) {
            return $signal;
        }

        $positions = [];

        foreach ($signal->stacktrace as $i => $frame) {
            $map = $maps->get((string) $this->bundlePath($frame));

            if ($map !== null && is_int($frame['line'] ?? null) && is_int($frame['column'] ?? null)) {
                $positions[$i] = $this->decode($map)?->lookup($frame['line'], $frame['column']);
            }
        }

        $frames = $signal->stacktrace;

        foreach (array_filter($positions) as $i => $position) {
            $minified = $frames[$i];

            $frames[$i] = array_filter([
                'file' => $this->sourcePath($position['source']),
                'line' => $position['line'],
                'column' => $position['column'],
                // A name at a position is what is called there: the caller's frame names this frame's function.
                'function' => $positions[$i + 1]['name'] ?? null,
                'in_app' => ! str_contains($position['source'], 'node_modules/'),
                'code' => $this->code($position['content'], $position['line']),
                'minified' => array_filter([
                    'file' => $minified['file'] ?? null,
                    'line' => $minified['line'] ?? null,
                    'column' => $minified['column'] ?? null,
                    'function' => $minified['function'] ?? null,
                ], fn ($v): bool => $v !== null),
            ], fn ($v): bool => $v !== null);
        }

        $top = isset($positions[0]) ? $frames[0] : null;

        return $signal->withFrames($frames, $top !== null ? mb_substr($top['file'], 0, 255) : $signal->file, $top['line'] ?? $signal->line);
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function bundlePath(array $frame): ?string
    {
        return is_string($frame['file'] ?? null) && $frame['file'] !== '' ? SourceMap::normalizePath($frame['file']) : null;
    }

    private function decode(SourceMap $map): ?SourceMapDecoder
    {
        return $this->decoded->remember($map->id.':'.$map->updated_at->getTimestamp(), function () use ($map): ?SourceMapDecoder {
            if (++$this->decodes > self::MAX_DECODES) {
                return null;
            }

            try {
                return SourceMapDecoder::decode((string) Storage::get($map->filePath()));
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        });
    }

    /**
     * ../../resources/js/app.tsx becomes resources/js/app.tsx: the same in every build.
     */
    private function sourcePath(string $source): string
    {
        return mb_substr((string) preg_replace('~^(?:[a-z][a-z0-9+.-]*://)?(?:\.{1,2}/)+~i', '', $source), 0, 500);
    }

    /**
     * @return array{start: int, lines: list<string>}|null
     */
    private function code(?string $content, int $line): ?array
    {
        if ($content === null) {
            return null;
        }

        $start = max(1, $line - intdiv(self::CODE_LINES, 2));
        $lines = array_slice(preg_split('/\r\n|\r|\n/', $content) ?: [], $start - 1, self::CODE_LINES);

        return $lines === [] ? null : ['start' => $start, 'lines' => array_map(fn (string $l): string => mb_substr($l, 0, 500), $lines)];
    }
}
