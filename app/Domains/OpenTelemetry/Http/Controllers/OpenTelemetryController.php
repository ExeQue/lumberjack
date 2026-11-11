<?php

declare(strict_types=1);

namespace App\Domains\OpenTelemetry\Http\Controllers;

use App\Domains\OpenTelemetry\Actions\IngestLogsAction;
use App\Domains\OpenTelemetry\Actions\IngestTracesAction;
use App\Domains\OpenTelemetry\Http\Requests\OtlpExportRequest;
use Illuminate\Http\Response;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceResponse;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceResponse;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse;

/**
 * OTLP/HTTP exports from an OpenTelemetry SDK or collector. See docs/opentelemetry.md.
 */
class OpenTelemetryController
{
    public function traces(OtlpExportRequest $request, IngestTracesAction $action): Response
    {
        $action->execute($request->project(), $request->decodeInto(new ExportTraceServiceRequest()));

        return $request->answer(new ExportTraceServiceResponse());
    }

    public function logs(OtlpExportRequest $request, IngestLogsAction $action): Response
    {
        $action->execute($request->project(), $request->decodeInto(new ExportLogsServiceRequest()));

        return $request->answer(new ExportLogsServiceResponse());
    }

    /**
     * Metrics are not stored. An SDK exports them by default, so they are taken and dropped
     * without an error it would log on every export.
     */
    public function metrics(OtlpExportRequest $request): Response
    {
        return $request->answer(new ExportMetricsServiceResponse());
    }
}
