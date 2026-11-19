<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * What an AI call asked the model for.
 */
enum AiOperation: string
{
    case Text = 'text';
    case Structured = 'structured';
    case Stream = 'stream';
    case Embeddings = 'embeddings';
    case Image = 'image';
    case Audio = 'audio';
    case Transcription = 'transcription';
    case Rerank = 'rerank';

    public function label(): string
    {
        return __('ui/ai.enums.operation.'.$this->value);
    }
}
