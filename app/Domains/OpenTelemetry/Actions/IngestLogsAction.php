<?php

declare(strict_types=1);

namespace App\Domains\OpenTelemetry\Actions;

use App\Domains\Logs\Enums\LogLevel;
use App\Domains\OpenTelemetry\Actions\Concerns\MapsOtlpData;
use App\Domains\OpenTelemetry\Support\StackTraceParser;
use App\Domains\Projects\Models\Project;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Logs\V1\LogRecord;

/**
 * Log records sent over OTLP, as the log events of an ingest batch: at error and above
 * they become issues, as any log line does. See docs/opentelemetry.md for the mapping.
 */
class IngestLogsAction
{
    use MapsOtlpData;

    public function __construct(
        private readonly StackTraceParser $stackTraceParser,
    ) {
    }

    public function execute(Project $project, ExportLogsServiceRequest $request): void
    {
        $parts = [];

        foreach ($request->getResourceLogs() as $resourceLogs) {
            $events = [];

            foreach ($resourceLogs->getScopeLogs() as $scopeLogs) {
                foreach ($scopeLogs->getLogRecords() as $record) {
                    $events[] = $this->event($record);
                }
            }

            $parts[] = [$this->envelope($resourceLogs->getResource()), $events];
        }

        $this->dispatch($project, $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(LogRecord $record): array
    {
        $context = $this->attributes($record->getAttributes());
        $body = $this->value($record->getBody());
        $exception = isset($context['exception.type']) || isset($context['exception.message']) ? $this->exception($context) : null;
        unset($context['exception.type'], $context['exception.message'], $context['exception.stacktrace']);

        return [
            'type' => 'log',
            'trace_id' => $this->hex($record->getTraceId()),
            'timestamp' => $this->seconds($record->getTimeUnixNano()) ?? $this->seconds($record->getObservedTimeUnixNano()),
            'level' => $this->level($record->getSeverityNumber(), $record->getSeverityText())->value,
            'message' => match (true) {
                $body === null => '',
                is_scalar($body) => (string) $body,
                default => (string) json_encode($body),
            },
            'context' => array_filter([...$context, 'exception' => $exception], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * OTLP's 24 severities in steps of four (trace, debug, info, warn, error, fatal), or
     * the record's own severity text when it sent no number.
     */
    private function level(int $number, string $text): LogLevel
    {
        return match (true) {
            $number >= 21 => LogLevel::Critical,
            $number >= 17 => LogLevel::Error,
            $number >= 13 => LogLevel::Warning,
            $number >= 9 => LogLevel::Info,
            $number >= 1 => LogLevel::Debug,
            default => match (strtolower($text)) {
                'warn' => LogLevel::Warning,
                'fatal' => LogLevel::Critical,
                default => LogLevel::fromName($text),
            },
        };
    }
}
