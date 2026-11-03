<?php

declare(strict_types=1);

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Projects\Actions\RotateStatsKeyAction;
use App\Domains\Projects\Actions\SetPublicStatsAction;
use App\Domains\Projects\Models\Project;
use App\Models\User;

covers(SetPublicStatsAction::class, RotateStatsKeyAction::class);

describe('public stats', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create();
        $this->user = User::factory()->create();
        $this->set = fn (bool $enabled, bool $titles = false) => app(SetPublicStatsAction::class)->execute($this->project, $this->user, $enabled, $titles);
    });

    it('creates a key when turned on and drops it when turned off, auditing both', function () {
        ($this->set)(true);
        $key = $this->project->stats_key;
        ($this->set)(false);

        expect($key)->toStartWith('ps_')
            ->and($this->project->refresh()->stats_key)->toBeNull()
            ->and(AdminAuditEntry::query()->pluck('action')->all())->toBe([AdminAction::PublicStatsEnabled, AdminAction::PublicStatsDisabled]);
    });

    it('keeps the key when only the titles setting changes', function () {
        ($this->set)(true);
        $key = $this->project->stats_key;
        ($this->set)(true, true);
        ($this->set)(true, true);

        expect($this->project->refresh()->stats_key)->toBe($key)
            ->and($this->project->stats_include_titles)->toBeTrue()
            ->and(AdminAuditEntry::query()->count())->toBe(2);
    });

    it('rotates only a key that exists', function () {
        expect(app(RotateStatsKeyAction::class)->execute($this->project, $this->user))->toBeNull();

        ($this->set)(true);
        $key = $this->project->stats_key;

        expect(app(RotateStatsKeyAction::class)->execute($this->project, $this->user))->not->toBe($key)->toStartWith('ps_');
    });
});
