<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            // Null: shared by the whole team. Set: only this project's rules can use it.
            $table->foreignIdFor(Project::class)->nullable()->after('team_id')->constrained()->cascadeOnDelete();
        });

        // A webhook is one app's endpoint, so it belongs to the project whose rules use it.
        // Used by several projects, it becomes one copy per project, same URL and secret.
        foreach (DB::table('notification_channels')->where('type', 'webhook')->get() as $channel) {
            $projects = DB::table('alert_rule_notification_channel as pivot')
                ->join('alert_rules', 'alert_rules.id', '=', 'pivot.alert_rule_id')
                ->where('pivot.notification_channel_id', $channel->id)
                ->distinct()->pluck('alert_rules.project_id');

            foreach ($projects->values() as $i => $projectId) {
                $id = $channel->id;

                if ($i > 0) {
                    $id = DB::table('notification_channels')->insertGetId([
                        ...collect((array) $channel)->except('id')->all(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);

                    DB::table('alert_rule_notification_channel')
                        ->where('notification_channel_id', $channel->id)
                        ->whereIn('alert_rule_id', DB::table('alert_rules')->where('project_id', $projectId)->select('id'))
                        ->update(['notification_channel_id' => $id]);
                }

                DB::table('notification_channels')->where('id', $id)->update(['project_id' => $projectId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Project::class);
        });
    }
};
