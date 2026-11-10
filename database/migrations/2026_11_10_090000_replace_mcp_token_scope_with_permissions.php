<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A token's single read or write scope becomes a level per area, with single-tool and
 * per-project changes. Existing tokens keep exactly what they could do: read everything,
 * write issues with the write scope, design screens with can_design_screens.
 */
return new class () extends Migration {
    private const array AREAS = ['issues', 'telemetry', 'health', 'schedule', 'alerts', 'data_sources', 'screens', 'project', 'team'];

    public function up(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->boolean('all_projects')->default(false)->after('name');
            $table->json('permissions')->nullable()->after('all_projects');
        });

        Schema::table('mcp_token_project', function (Blueprint $table): void {
            $table->json('permissions')->nullable();
        });

        DB::table('mcp_tokens')->orderBy('id')->each(function (object $token): void {
            $permissions = array_fill_keys(self::AREAS, ['level' => 'read']);

            if ($token->scope === 'write') {
                $permissions['issues'] = ['level' => 'write'];
            }

            if ((bool) $token->can_design_screens) {
                $permissions['screens'] = ['level' => 'write'];
            }

            DB::table('mcp_tokens')->where('id', $token->id)->update(['permissions' => json_encode($permissions, JSON_THROW_ON_ERROR)]);
        });

        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->dropColumn(['scope', 'can_design_screens']);
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->string('scope')->default('read');
            $table->boolean('can_design_screens')->default(false);
        });

        DB::table('mcp_tokens')->orderBy('id')->each(function (object $token): void {
            $permissions = (array) json_decode((string) $token->permissions, true);
            $level = fn (string $area): string => (string) ($permissions[$area]['level'] ?? 'none');

            DB::table('mcp_tokens')->where('id', $token->id)->update([
                'scope' => in_array($level('issues'), ['write', 'manage'], true) ? 'write' : 'read',
                'can_design_screens' => in_array($level('screens'), ['write', 'manage'], true),
            ]);
        });

        Schema::table('mcp_tokens', function (Blueprint $table): void {
            $table->dropColumn(['all_projects', 'permissions']);
        });

        Schema::table('mcp_token_project', function (Blueprint $table): void {
            $table->dropColumn('permissions');
        });
    }
};
