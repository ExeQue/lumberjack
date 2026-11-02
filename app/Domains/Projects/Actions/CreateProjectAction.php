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
     * @return array{project: Project, token: string}
     */
    public function execute(Team $team, string $name): array
    {
        $token = ProjectKeys::newServerToken();

        $project = DB::transaction(function () use ($team, $name, $token): Project {
            $project = $team->projects()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($team, $name),
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
