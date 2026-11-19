<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Requests;

use App\Domains\Ai\Support\AiKeyFingerprint;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Finding a key by the SHA-256 of driver and key, made in the browser once per driver it
 * could be for, so nobody has to know which driver the app reported. Only hashes are
 * accepted: a raw key sent by mistake is refused, not looked up.
 */
class LookUpAiKeyRequest extends FormRequest
{
    /** One hash per driver the browser tried. */
    public const int MAX_HASHES = 32;

    /**
     * The drivers of laravel/ai (its config's `driver`s) and Prism (its Provider enum): what
     * the library hashes a key with when the app names no other.
     */
    public const array DRIVERS = [
        'anthropic', 'azure', 'bedrock', 'cohere', 'deepseek', 'eleven', 'elevenlabs', 'gemini', 'groq', 'jina',
        'mistral', 'ollama', 'openai', 'openai-compatible', 'openrouter', 'perplexity', 'typesafe', 'voyageai', 'xai', 'z',
    ];

    /**
     * The drivers to hash a key with: the project's own keys' first, then the built-in ones,
     * as many as one lookup takes.
     *
     * @return list<string>
     */
    public static function drivers(Project $project): array
    {
        $seen = $project->aiKeys()->whereNotNull('driver')->distinct()->orderBy('driver')
            ->limit(self::MAX_HASHES)->pluck('driver')->all();

        return array_slice(array_values(array_unique([...$seen, ...self::DRIVERS])), 0, self::MAX_HASHES);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hashes' => ['required', 'array', 'list', 'max:'.self::MAX_HASHES],
            'hashes.*' => ['required', 'string', 'regex:'.AiKeyFingerprint::SHA256_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['hashes.*.regex' => __('ui/ai.lookup.invalid')];
    }
}
