<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Requests;

use App\Domains\Ingest\Http\Requests\Concerns\DecodesIngestBody;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class BrowserIngestRequest extends FormRequest
{
    use DecodesIngestBody;

    public const int MAX_EVENTS = 50;

    // Crawlers, link previews and automated browsers; the SDK skips them too.
    public const string BOT_PATTERN = '/bot\\b|bot\\/|crawl|spider|slurp|headless|lighthouse|pagespeed|prerender|phantom|puppeteer|playwright|selenium|facebookexternalhit|embedly|preview/i';

    private ?Project $resolvedProject = null;

    /**
     * The key and origin are checked before the body is read, so nobody without them can
     * make Lumberjack inflate or parse anything. Browsers send plain text, never gzip.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->authorize()) {
            $this->failedAuthorization();
        }

        if ($this->hasHeader('Content-Encoding') && strtolower((string) $this->header('Content-Encoding')) !== 'identity') {
            abort(415, 'Browser batches are not compressed.');
        }

        $this->decodeIngestBody();
    }

    public function authorize(): bool
    {
        $project = $this->project();

        return $project !== null && $project->allowsOrigin($this->header('Origin'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'v' => ['required', 'integer', 'in:1'],
            'environment' => ['nullable', 'string', 'max:64'],
            'release' => ['nullable', 'string', 'max:64'],
            'events' => ['required', 'array', 'max:'.self::MAX_EVENTS],
            'events.*.type' => ['required', 'string', 'in:exception,vital'],
        ];
    }

    public function fromBot(): bool
    {
        $agent = (string) $this->userAgent();

        return $agent === '' || preg_match(self::BOT_PATTERN, $agent) === 1;
    }

    public function project(): ?Project
    {
        $key = $this->query('key');

        return $this->resolvedProject ??= is_string($key) && $key !== ''
            ? Project::query()->where('public_key', $key)->first()
            : null;
    }
}
