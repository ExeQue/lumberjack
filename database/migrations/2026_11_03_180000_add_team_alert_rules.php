<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team-wide alert rules, which a project uses unless it has its own rule of that type.
 * A rule now belongs to a team or to a project, never both, and a team has one per type.
 *
 * Nothing sends differently afterwards. Per team and type:
 *
 * - When every project of the team has a rule of that type and all of them are the same
 *   (on or off, environments, thresholds, channels) and use only shared channels, one of
 *   them becomes the team rule: the others' send history and queue states move onto it
 *   and they are deleted, so every project inherits exactly what it had.
 * - Otherwise the team rule is what a new project used to get (on when the type is on by
 *   default, every shared channel) and each project keeps its rule as an override. A
 *   project that had no rule of that type gets an off one, so it does not start
 *   inheriting.
 *
 * Queue backlog states gain the project they are about, since a team rule watches the
 * queues of several projects.
 *
 * down() copies each team rule into every project of the team that inherits it, with its
 * channels, send history and queue states, and drops the team rules.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->foreignIdFor(Team::class)->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->change();
            $table->unique(['team_id', 'type']);
        });

        DB::statement('ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_one_owner CHECK (num_nonnulls(team_id, project_id) = 1)');

        Schema::table('queue_backlogs', function (Blueprint $table) {
            $table->foreignIdFor(Project::class)->nullable()->after('alert_rule_id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE queue_backlogs SET project_id = alert_rules.project_id FROM alert_rules WHERE alert_rules.id = queue_backlogs.alert_rule_id');

        Schema::table('queue_backlogs', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->dropUnique(['alert_rule_id', 'connection', 'queue']);
            $table->unique(['alert_rule_id', 'project_id', 'connection', 'queue']);
        });

        $types = ['new_issue', 'regression', 'note', 'escalation', 'status_change', 'queue_backlog', 'health_degraded', 'health_down', 'health_recovered'];
        $offByDefault = ['note', 'status_change'];
        $now = now();

        foreach (DB::table('teams')->pluck('id') as $teamId) {
            $projectIds = DB::table('projects')->where('team_id', $teamId)->pluck('id');
            $shared = DB::table('notification_channels')->where('team_id', $teamId)->whereNull('project_id')->orderBy('id')->pluck('id');

            foreach ($types as $type) {
                $rules = DB::table('alert_rules')->whereIn('project_id', $projectIds)->where('type', $type)->orderBy('id')->get();
                $channels = DB::table('alert_rule_notification_channel')->whereIn('alert_rule_id', $rules->pluck('id'))->get()->groupBy('alert_rule_id');
                $shapes = $rules->map(fn (stdClass $rule): string => $this->shape($rule, $channels->get($rule->id, collect())))->unique();
                $onlyShared = $channels->flatten()->pluck('notification_channel_id')->diff($shared)->isEmpty();

                if ($projectIds->isNotEmpty() && $rules->count() === $projectIds->count() && $shapes->count() === 1 && $onlyShared) {
                    $kept = $rules->first();
                    $copies = $rules->slice(1)->pluck('id');

                    DB::table('alert_sends')->whereIn('alert_rule_id', $copies)->update(['alert_rule_id' => $kept->id]);
                    DB::table('queue_backlogs')->whereIn('alert_rule_id', $copies)->update(['alert_rule_id' => $kept->id]);
                    DB::table('alert_rules')->whereIn('id', $copies)->delete();
                    DB::table('alert_rules')->where('id', $kept->id)->update(['team_id' => $teamId, 'project_id' => null, 'updated_at' => $now]);

                    continue;
                }

                $id = DB::table('alert_rules')->insertGetId(['team_id' => $teamId, 'type' => $type, 'enabled' => ! in_array($type, $offByDefault, true), 'created_at' => $now, 'updated_at' => $now]);
                DB::table('alert_rule_notification_channel')->insert($shared->map(fn (int $channel): array => ['alert_rule_id' => $id, 'notification_channel_id' => $channel])->all());

                foreach ($projectIds->diff($rules->pluck('project_id')) as $projectId) {
                    DB::table('alert_rules')->insert(['project_id' => $projectId, 'type' => $type, 'enabled' => false, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $now = now();

        foreach (DB::table('alert_rules')->whereNotNull('team_id')->get() as $rule) {
            $channels = DB::table('alert_rule_notification_channel')->where('alert_rule_id', $rule->id)->pluck('notification_channel_id');
            $inheriting = DB::table('projects')->where('team_id', $rule->team_id)
                ->whereNotIn('id', DB::table('alert_rules')->where('type', $rule->type)->whereNotNull('project_id')->select('project_id'))
                ->pluck('id');

            foreach ($inheriting as $projectId) {
                $id = DB::table('alert_rules')->insertGetId([
                    'project_id' => $projectId, 'type' => $rule->type, 'enabled' => $rule->enabled, 'environments' => $rule->environments, 'settings' => $rule->settings,
                    'created_at' => $rule->created_at, 'updated_at' => $now,
                ]);

                DB::table('alert_rule_notification_channel')->insert($channels->map(fn (int $channel): array => ['alert_rule_id' => $id, 'notification_channel_id' => $channel])->all());
                DB::table('alert_sends')->where('alert_rule_id', $rule->id)->whereIn('issue_id', DB::table('issues')->where('project_id', $projectId)->select('id'))->update(['alert_rule_id' => $id]);
                DB::table('queue_backlogs')->where('alert_rule_id', $rule->id)->where('project_id', $projectId)->update(['alert_rule_id' => $id]);
            }
        }

        DB::table('alert_rules')->whereNotNull('team_id')->delete();

        Schema::table('queue_backlogs', function (Blueprint $table) {
            $table->dropUnique(['alert_rule_id', 'project_id', 'connection', 'queue']);
            $table->unique(['alert_rule_id', 'connection', 'queue']);
            $table->dropConstrainedForeignIdFor(Project::class);
        });

        DB::statement('ALTER TABLE alert_rules DROP CONSTRAINT alert_rules_one_owner');

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'type']);
            $table->dropConstrainedForeignIdFor(Team::class);
            $table->foreignId('project_id')->nullable(false)->change();
        });
    }

    /**
     * What a rule does, comparable between projects: no environments and an empty list
     * both mean every environment.
     *
     * @param  Collection<int, stdClass>  $channels
     */
    private function shape(stdClass $rule, Collection $channels): string
    {
        $environments = json_decode((string) $rule->environments, true) ?: [];
        $settings = json_decode((string) $rule->settings, true) ?: [];
        sort($environments);
        ksort($settings);

        return json_encode([(bool) $rule->enabled, $environments, $settings, $channels->pluck('notification_channel_id')->sort()->values()->all()], JSON_THROW_ON_ERROR);
    }
};
