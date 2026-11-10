<?php

declare(strict_types=1);

use App\Domains\Mcp\Servers\LumberjackServer;
use App\Domains\Mcp\Support\McpToolCatalog;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Support\Facades\File;

/**
 * A tool is only safe when the token's permission is checked for it: it declares what it
 * needs, it goes through the shared checks, and it is registered so the catalog knows it.
 */
describe('MCP tools', function () {
    test('declare the permission they need', function (string $class) {
        expect(fn () => McpToolCatalog::describe($class))->not->toThrow(LogicException::class);
    })->with(LumberjackServer::TOOLS);

    test('go through the shared permission checks', function (string $class) {
        expect(class_uses_recursive($class))->toHaveKey(UsesMcpContext::class);
    })->with(LumberjackServer::TOOLS);

    test('have a line for the permission picker', function (string $class) {
        $name = McpToolCatalog::describe($class)['name'];

        expect(trans()->has('mcp.tools.'.$name))->toBeTrue("lang/en/mcp.php has no tools.{$name}");
    })->with(LumberjackServer::TOOLS);

    test('are all registered on the server', function () {
        $classes = collect(File::allFiles(app_path('Domains/Mcp/Tools')))
            ->reject(fn (SplFileInfo $file): bool => str_contains($file->getPath(), 'Concerns'))
            ->map(fn (SplFileInfo $file): string => 'App\\Domains\\Mcp\\Tools\\'.str_replace('/', '\\', substr($file->getPathname(), strlen(app_path('Domains/Mcp/Tools/')), -4)))
            ->sort()->values()->all();

        expect($classes)->toEqualCanonicalizing(LumberjackServer::TOOLS);
    });

    test('are documented with the permission they need', function (string $class) {
        $tool = McpToolCatalog::describe($class);
        $needs = $tool['area'] === null ? 'any token' : '`'.$tool['area']->value.': '.$tool['level']->value.'`';

        // Prettier pads the columns, so any run of spaces separates the cells.
        $row = '/^\|\s*`'.preg_quote($tool['name'], '/').'`\s*\|\s*'.preg_quote($needs, '/').'\s*\|/m';

        expect(preg_match($row, (string) file_get_contents(base_path('docs/mcp.md'))))->toBe(1, "docs/mcp.md has no row for {$tool['name']} needing {$needs}");
    })->with(LumberjackServer::TOOLS);

    test('have unique names', function () {
        $names = array_map(fn (string $class): string => McpToolCatalog::describe($class)['name'], LumberjackServer::TOOLS);

        expect($names)->toHaveCount(count(array_unique($names)));
    });
});
