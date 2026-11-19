<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Projects\Exceptions\CurrencyMismatchException;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Actions\SaveScreenAction;
use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves a project, with all its data, to another team. Telemetry and project settings
 * carry only the project and go along, its own AI prices too; what tied it to the old team is let go: its
 * screens, its team-wide alert rules and channels, MCP tokens, and people not in the new team.
 */
class MoveProjectToTeamAction
{
    public function __construct(
        private readonly SaveScreenAction $saveScreen,
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    /**
     * @param  string  $slug  free in the target team (validated by the caller), as is $issuePrefix
     */
    public function execute(Project $project, Team $target, string $slug, string $issuePrefix, User $by): Project
    {
        if (self::currencyBlocks($project, $target)) {
            throw new CurrencyMismatchException(__('projects.move_currency', ['from' => $project->team->currency, 'to' => $target->currency]));
        }

        DB::transaction(function () use ($project, $target, $slug, $issuePrefix, $by): void {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            // Again under the locks: either team's currency may have changed since the check above.
            $from = Team::query()->whereKey($project->team_id)->sharedLock()->firstOrFail();
            $project->setRelation('team', $from);
            $target = Team::query()->whereKey($target->id)->sharedLock()->firstOrFail();

            if (self::currencyBlocks($project, $target)) {
                throw new CurrencyMismatchException(__('projects.move_currency', ['from' => $from->currency, 'to' => $target->currency]));
            }

            // Its own channels go with it; the old team's are no longer its to send to.
            DB::table('notification_channels')->where('project_id', $project->id)->update(['team_id' => $target->id]);
            $staleRules = DB::table('alert_rules')->where('team_id', $from->id)->select('id');
            $staleChannels = DB::table('notification_channels')->where('team_id', $from->id)->select('id');
            DB::table('project_alert_rule_channel')->where('project_id', $project->id)
                ->where(fn ($q) => $q->whereIn('alert_rule_id', $staleRules)->orWhereIn('notification_channel_id', $staleChannels))
                ->delete();
            DB::table('alert_rule_notification_channel')
                ->where(fn ($q) => $q->whereIn('alert_rule_id', DB::table('alert_rules')->where('project_id', $project->id)->select('id'))->whereIn('notification_channel_id', $staleChannels))
                ->orWhere(fn ($q) => $q->whereIn('alert_rule_id', $staleRules)->whereIn('notification_channel_id', DB::table('notification_channels')->where('project_id', $project->id)->select('id')))
                ->delete();

            // Its own AI prices go with it; its old team's no longer apply.
            DB::table('ai_prices')->where('project_id', $project->id)->update(['team_id' => $target->id]);

            // Tokens of the new team get no access by themselves.
            DB::table('mcp_token_project')->where('project_id', $project->id)->delete();

            $members = DB::table('team_user')->where('team_id', $target->id)->select('user_id');
            $issues = DB::table('issues')->where('project_id', $project->id)->select('id');
            DB::table('issues')->where('project_id', $project->id)->whereNotNull('assigned_user_id')->whereNotIn('assigned_user_id', $members)->update(['assigned_user_id' => null]);
            DB::table('issue_watchers')->whereIn('issue_id', $issues)->whereNotIn('user_id', $members)->delete();

            $this->removeFromScreens($project, $from);

            $project->update(['team_id' => $target->id, 'slug' => $slug, 'issue_prefix' => $issuePrefix]);
            $this->audit->execute($by, AdminAction::ProjectMoved, $project->name, ['project' => $project->uuid, 'from' => $from->name, 'to' => $target->name]);
        });

        $project->refresh()->setRelation('team', $target);

        return $project;
    }

    /**
     * Whether the project's own AI prices or cost budgets, in its team's currency, would land
     * in a team with another one.
     */
    public static function currencyBlocks(Project $project, Team $target): bool
    {
        return $project->team->currency !== $target->currency
            && (AiPrice::query()->where('project_id', $project->id)->exists() || AiBudget::query()->whereProject($project)->whereCost()->exists());
    }

    /**
     * Takes the project out of every scope of the old team's screens. A scope left with no
     * project would mean every project of the team, so it keeps NO_PROJECT instead and shows
     * nothing, as a widget pointing only outside its screen's projects already does.
     */
    private function removeFromScreens(Project $project, Team $from): void
    {
        $uuid = $project->uuid;
        $strip = function (mixed $scope) use ($uuid): mixed {
            $projects = is_array($scope) ? (array) ($scope['projects'] ?? []) : [];

            if (! in_array($uuid, $projects, true)) {
                return $scope;
            }

            return [...$scope, 'projects' => array_values(array_diff($projects, [$uuid])) ?: [WidgetScope::NO_PROJECT]];
        };

        // A widget has a scope, and so may each value it rotates through.
        $scoped = fn (mixed $item): mixed => is_array($item) && array_key_exists('scope', $item) ? [...$item, 'scope' => $strip($item['scope'])] : $item;

        // A screen it is not on stays as it is: saving skips an unchanged one.
        Screen::query()->where('team_id', $from->id)->get()->each(function (Screen $screen) use ($strip, $scoped): void {
            $screen->scope = $strip($screen->scope);
            $screen->widgets = array_map(fn (array $widget): array => [
                ...$scoped($widget),
                ...(is_array($widget['rotate'] ?? null) ? ['rotate' => array_map($scoped, $widget['rotate'])] : []),
            ], $screen->widgets);
            $this->saveScreen->execute($screen);
        });
    }
}
