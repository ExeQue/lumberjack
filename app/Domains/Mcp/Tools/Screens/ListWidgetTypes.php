<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Presenters\ScreenPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Screens, McpLevel::Read)]
class ListWidgetTypes extends Tool
{
    use UsesScreens;

    protected string $name = 'list_widget_types';

    protected string $description = 'The widget catalog. Without a type: every type and its sources. With a type: its option fields and full sources.';

    public function __construct(
        private readonly ScreenPresenter $presenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('A widget type, for its option fields.'),
        ];
    }

    public function handle(Request $request): Response
    {
        ['schema' => $widgets, 'sources' => $sources] = $this->presenter->catalog();
        $type = $request->validate(['type' => ['nullable', 'string']])['type'] ?? null;

        if ($type === null) {
            return Response::json(['types' => array_map(fn (string $name): array => ['type' => $name, 'sources' => array_column($sources[$name] ?? [], 'id')], array_keys($widgets))]);
        }

        return isset($widgets[$type])
            ? Response::json(['type' => $type, 'schema' => self::withoutIcons($widgets[$type]), 'sources' => $sources[$type] ?? []])
            : Response::error('Unknown widget type; see list_widget_types without a type.');
    }

    /**
     * A type's sections without the pictures its choices have for the designer (the QR
     * library's SVGs): an agent picks by name and label.
     *
     * @param  list<array{id: string, fields: list<array<string, mixed>>}>  $sections
     * @return list<array{id: string, fields: list<array<string, mixed>>}>
     */
    private static function withoutIcons(array $sections): array
    {
        return array_map(fn (array $section): array => [...$section, 'fields' => array_map(fn (array $field): array => array_diff_key($field, ['icons' => true]), $section['fields'])], $sections);
    }
}
