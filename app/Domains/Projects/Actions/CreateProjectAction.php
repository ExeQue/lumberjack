<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProjectAction
{
    /**
     * @param  ?string  $issuePrefix  as chosen (validated by the caller); null derives a unique one from the name
     * @return array{project: Project, token: string}
     */
    public function execute(Team $team, string $name, ?string $issuePrefix = null): array
    {
        $token = ProjectKeys::newServerToken();

        $project = DB::transaction(function () use ($team, $name, $token, $issuePrefix): Project {
            $slug = $this->uniqueSlug($team, $name);
            $project = $team->projects()->create([
                'name' => $name,
                'slug' => $slug,
                'issue_prefix' => $issuePrefix ?? $this->uniquePrefix($team, $slug),
                'token_hash' => ProjectKeys::hash($token),
                'token_last_four' => substr($token, -4),
                'public_key' => ProjectKeys::newPublicKey(),
                'allowed_origins' => [],
            ]);

            $project->logChannels()->create(['name' => LogChannel::DEFAULT]);

            $channelIds = $team->notificationChannels()->whereTeamWide()->pluck('id');

            foreach (AlertRuleType::cases() as $type) {
                $project->alertRules()->create(['type' => $type, 'enabled' => $type->enabledByDefault()])->channels()->attach($channelIds);
            }

            return $project;
        });

        return ['project' => $project, 'token' => $token];
    }

    /**
     * DEMO from demo-shop: the slug's letters and digits, at most 8, made unique in the
     * team (DEMO2) so a reference points at one issue.
     */
    private function uniquePrefix(Team $team, string $slug): string
    {
        $base = mb_substr(strtoupper((string) preg_replace('/[^a-z0-9]/i', '', $slug)), 0, 8);
        $base = preg_match('/^[A-Z]/', $base) === 1 ? $base : 'P'.mb_substr($base, 0, 7);
        $prefix = $base;
        $suffix = 2;

        while ($team->projects()->where('issue_prefix', $prefix)->exists()) {
            $prefix = mb_substr($base, 0, 8 - strlen((string) $suffix)).$suffix++;
        }

        return $prefix;
    }

    private function uniqueSlug(Team $team, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while ($team->projects()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
