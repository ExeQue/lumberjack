<?php

declare(strict_types=1);

use App\Domains\Health\Actions\RecordHeartbeatPingAction;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Http\Controllers\HealthPingController;
use App\Domains\Health\Models\HealthCheck;

covers(HealthPingController::class, RecordHeartbeatPingAction::class);

describe('the heartbeat ping URL', function () {
    it('records a ping by GET or POST', function () {
        $check = HealthCheck::factory()->monitored(HealthCheckType::Heartbeat, [])->create();

        $this->get('/api/health/ping/'.$check->ping_token)->assertOk()->assertJson(['ok' => true]);
        $this->post('/api/health/ping/'.$check->ping_token)->assertOk();

        expect($check->refresh()->last_ping_at)->not->toBeNull()
            ->and($check->results()->pluck('status')->all())->toBe([HealthResultStatus::Ok, HealthResultStatus::Ok]);
    });

    it('answers 404 to an unknown token or an archived check', function () {
        $check = HealthCheck::factory()->monitored(HealthCheckType::Heartbeat, [])->create(['archived_at' => now()]);

        $this->get('/api/health/ping/'.$check->ping_token)->assertNotFound();
        $this->get('/api/health/ping/'.str_repeat('0', 40))->assertNotFound();
    });
});
