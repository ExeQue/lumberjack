<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Support\JsonPath;
use App\Domains\Screens\Actions\PreviewDataSourceAction;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Models\Screen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DataSourcePreviewController
{
    /** JSONPath help for an External JSON widget in the designer (PreviewDataSourceAction). */
    public function __invoke(Request $request, Screen $screen, DataSource $dataSource, PreviewDataSourceAction $action): JsonResponse
    {
        Gate::authorize('update', $screen);
        // Only a source of the screen's own team, as the widget reads it.
        abort_unless($dataSource->project->team_id === $screen->team_id, 404);
        Gate::authorize('view', $dataSource);

        $path = ['nullable', 'string', 'max:'.JsonPath::MAX_PATH];
        $request->validate([
            'type' => ['required', Rule::in(['stat', 'gauge', 'chart', 'list'])],
            'path' => $path,
            'label_path' => $path,
            'value_path' => $path,
        ]);

        return response()->json($action->execute(
            $dataSource,
            WidgetType::from($request->string('type')->toString()),
            trim($request->string('path')->toString()),
            $request->string('label_path')->toString(),
            $request->string('value_path')->toString(),
        ));
    }
}
