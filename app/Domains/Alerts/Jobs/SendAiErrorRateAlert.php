<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Ai\Models\AiKey;
use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\AiErrorRateAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Notifications\Actions\NotifyProjectMembersAction;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\AiAlertNotification;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAiErrorRateAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** A project or rule deleted since has nothing left to tell. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly AlertRule $rule,
        public readonly Project $project,
        public readonly string $fingerprint,
        public readonly int $calls,
        public readonly int $errors,
        public readonly string $since,
        public readonly bool $recovered,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SendProjectAlertAction $action, NotifyProjectMembersAction $members): void
    {
        $key = $this->project->aiKeys()->whereFingerprint($this->fingerprint)->first();
        $alert = new AiErrorRateAlert(
            $this->project,
            $this->fingerprint,
            $key,
            $this->calls,
            $this->errors,
            $this->rule->aiErrorRateSettings(),
            CarbonImmutable::parse($this->since),
            $this->recovered,
            $key instanceof AiKey ? route('ai.keys.show', ['project' => $this->project, 'aiKey' => $key->uuid, 'errors' => 1]) : route('ai.index', $this->project),
        );

        $action->execute($this->rule, $this->project, $alert);
        $members->execute($this->project, new AiAlertNotification(PushMessage::fromAlert($alert, $this->project, 'ai-errors-'.$this->project->uuid.'-'.substr($this->fingerprint, 0, 8))));
    }
}
