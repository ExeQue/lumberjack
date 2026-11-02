<?php

declare(strict_types=1);

namespace App\Domains\Issues\Data;

use App\Domains\Issues\Enums\IssueSource;
use Carbon\CarbonImmutable;

/**
 * One error as reported by an SDK or a log line, before it is grouped.
 */
final readonly class IssueSignal
{
    /**
     * @param  list<array<string, mixed>>  $stacktrace
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public IssueSource $source,
        public string $title,
        public string $message,
        public ?string $file,
        public ?int $line,
        public array $stacktrace,
        public array $context,
        public CarbonImmutable $occurredAt,
        public ?string $traceId = null,
        public ?string $environment = null,
        public ?string $release = null,
        public ?string $host = null,
        public ?string $logChannel = null,
        public ?string $customFingerprint = null,
    ) {
    }
}
