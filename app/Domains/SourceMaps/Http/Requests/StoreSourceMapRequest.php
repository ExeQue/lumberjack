<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Http\Requests;

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Support\SourceMapDecoder;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class StoreSourceMapRequest extends FormRequest
{
    /** Per map file, in kilobytes. Ingest batches have their own, much smaller, limit. */
    public const int MAX_KILOBYTES = 20 * 1024;

    public function authorize(): bool
    {
        return $this->attributes->get('project') instanceof Project;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, function (string $attribute, mixed $file, Closure $fail): void {
                // Refused now rather than found broken when the first error arrives.
                try {
                    SourceMapDecoder::decode($file instanceof UploadedFile ? (string) $file->get() : '');
                } catch (InvalidArgumentException) {
                    $fail(__('source_maps.invalid'));
                }
            }],
            'path' => ['required', 'string', 'max:500'],
            'release' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function map(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('file');
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->attributes->get('project');
    }
}
