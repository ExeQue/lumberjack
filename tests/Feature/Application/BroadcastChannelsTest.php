<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\TvViewer;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

describe('the project channel', function () {
    beforeEach(function () {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();
        $this->join = (fn (): array => $this->channels)->call($broadcaster)['project.{projectUuid}'];
        $this->guards = (fn (): array => $this->channelOptions)->call($broadcaster)['project.{projectUuid}'];
        $this->project = Project::factory()->create();
    });

    it('lets members and the team\'s displays listen, so a TV updates live', function () {
        $member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();

        expect(($this->join)($member, $this->project->uuid))->toBeTrue()
            ->and(($this->join)(User::factory()->inTeam()->create(), $this->project->uuid))->toBeFalse()
            ->and(($this->join)(new TvViewer(collect([Display::factory()->for($this->project->team)->create()]), collect()), $this->project->uuid))->toBeTrue()
            ->and(($this->join)(new TvViewer(collect([Display::factory()->create()]), collect([TvPairing::factory()->create()])), $this->project->uuid))->toBeFalse()
            ->and(($this->join)($member, (string) Str::uuid()))->toBeFalse()
            ->and($this->guards)->toBe(['guards' => ['web', 'tv']]);
    });
});

describe('the team channel', function () {
    it('lets the team\'s members listen and no one else', function () {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();
        $join = (fn (): array => $this->channels)->call($broadcaster)['team.{teamUuid}'];
        $member = User::factory()->inTeam(role: TeamRole::Member)->create();

        expect($join($member, $member->currentTeam->uuid))->toBeTrue()
            ->and($join(User::factory()->inTeam()->create(), $member->currentTeam->uuid))->toBeFalse()
            ->and($join($member, (string) Str::uuid()))->toBeFalse();
    });
});

describe('the admin channel', function () {
    it('lets system administrators listen, and not a suspended one', function () {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();
        $join = (fn (): array => $this->channels)->call($broadcaster)['admin'];

        expect($join(User::factory()->create(['is_admin' => true])))->toBeTrue()
            ->and($join(User::factory()->inTeam()->create()))->toBeFalse()
            ->and($join(User::factory()->create(['is_admin' => true, 'suspended_at' => now()])))->toBeFalse();
    });
});
