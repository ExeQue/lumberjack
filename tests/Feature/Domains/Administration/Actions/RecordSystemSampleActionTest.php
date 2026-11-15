<?php

declare(strict_types=1);

use App\Domains\Administration\Actions\RecordSystemSampleAction;
use App\Domains\Administration\Console\Commands\SampleSystemCommand;
use App\Domains\Administration\Models\SystemSample;
use App\Domains\Administration\Notifications\SystemDiskNotification;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Administration\FakesSystemProbe;

covers(RecordSystemSampleAction::class, SampleSystemCommand::class, SystemDiskNotification::class);

uses(FakesSystemProbe::class);

beforeEach(function () {
    $this->admin = User::factory()->systemAdmin()->create();
    $this->sample = function (?float $disk): SystemSample {
        $this->fakeSystem(disk: $disk);

        return app(RecordSystemSampleAction::class)->execute();
    };
});

describe('sampling the server', function () {
    it('records what the server is doing', function () {
        $this->fakeSystem(cpu: 0.5, memory: 0.25, disk: 0.4);
        $this->artisan(SampleSystemCommand::class)->assertSuccessful();

        $sample = SystemSample::query()->sole();

        expect($sample->cpu_load_1m)->toBe(2.0)
            ->and($sample->cpu_cores)->toBe(4.0)
            ->and($sample->memory_used_bytes)->toBe(2 * 1024 ** 3)
            ->and($sample->memory_total_bytes)->toBe(8 * 1024 ** 3)
            ->and($sample->diskUsed())->toBe(0.4);
    });

    it('records a sample without the readings it could not take', function () {
        Notification::fake();

        expect(($this->sample)(null)->disk_total_bytes)->toBeNull();
        Notification::assertNothingSent();
    });
});

describe('a disk that is nearly full', function () {
    it('is told to the administrators once, and again only after it has been under 85%', function () {
        Notification::fake();
        User::factory()->create();
        User::factory()->systemAdmin()->create(['suspended_at' => now()]);

        ($this->sample)(0.89);
        Notification::assertNothingSent();

        ($this->sample)(0.92);
        ($this->sample)(0.95);
        // Back under the limit, but not far enough to count as over.
        ($this->sample)(0.87);
        ($this->sample)(0.93);
        Notification::assertSentToTimes($this->admin, SystemDiskNotification::class, 1);
        Notification::assertCount(1);

        ($this->sample)(0.6);
        ($this->sample)(0.91);
        Notification::assertSentToTimes($this->admin, SystemDiskNotification::class, 2);
    });

    it('mails how full it is and where to look', function () {
        $subjects = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$subjects): void {
            $subjects[] = $event->message->getSubject();
            expect($event->message->getHtmlBody())->toContain('8.0 GB')->toContain(route('admin.system'))->toContain(route('admin.retention'));
        });

        ($this->sample)(0.92);

        expect($subjects)->toBe(['The disk of your Lumberjack server is 92% full']);
    });
});
