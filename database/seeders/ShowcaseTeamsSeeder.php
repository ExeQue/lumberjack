<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Alerts\Actions\CustomizeAlertRuleAction;
use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Actions\SetQueueAlertThresholdAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Issues\Models\Label;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Actions\CreateProjectAction;
use App\Domains\Projects\Models\Project;
use App\Domains\Repositories\Models\ProjectRepository;
use App\Domains\Retention\Actions\SaveRetentionPolicyAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Database\Seeders\Support\Showcase;
use Database\Seeders\Support\ShowcaseCatalog;
use Illuminate\Database\Seeder;

/**
 * The showcase teams, their people and projects, and what each project is set up
 * with: labels, log channels, a repository, alert channels and rules, retention.
 * Only adds: a team, person or project that is there already is kept as it is, and
 * a project that existed before is not set up again.
 */
class ShowcaseTeamsSeeder extends Seeder
{
    /** Labels every showcase project has. */
    private const array LABELS = [
        'checkout' => LabelColor::Orange, 'payments' => LabelColor::Red, 'customer-facing' => LabelColor::Purple,
        'flaky' => LabelColor::Yellow, 'needs-repro' => LabelColor::Gray, 'infra' => LabelColor::Teal, 'p1' => LabelColor::Red,
    ];

    public function __construct(
        private readonly CreateProjectAction $createProject,
        private readonly SaveNotificationChannelAction $saveChannel,
        private readonly CustomizeAlertRuleAction $customizeRule,
        private readonly SetQueueAlertThresholdAction $setThreshold,
        private readonly SaveRetentionPolicyAction $saveRetention,
    ) {
    }

    public function run(): void
    {
        foreach (ShowcaseCatalog::teams() as $name => $people) {
            $team = Team::query()->where('name', $name)->first() ?? Team::factory()->create(['name' => $name]);

            foreach ($people as $person) {
                $this->member($team, $person);
            }
        }

        foreach (ShowcaseCatalog::projects() as $spec) {
            if (Showcase::project($spec) === null) {
                $this->setUp($spec);
            }
        }

        $this->channels();
        $this->retention();
    }

    /** @param  array{name: string, email: string, role: string}  $person */
    private function member(Team $team, array $person): void
    {
        $user = User::query()->where('email', $person['email'])->first();
        $role = TeamRole::from($person['role']);

        if ($user === null) {
            User::factory()->inTeam($team, $role)->create(['name' => $person['name'], 'email' => $person['email'], 'password' => ShowcaseCatalog::PASSWORD]);

            return;
        }

        if ($team->roleOf($user) === null) {
            $team->members()->attach($user, ['role' => $role->value]);
        }
    }

    /** @param  array<string, mixed>  $spec */
    private function setUp(array $spec): void
    {
        $team = Team::query()->where('name', $spec['team'])->sole();
        $project = $this->createProject->execute($team, $spec['name'], $spec['prefix'])['project'];
        $created = Showcase::now()->subDays(45);

        $project->forceFill([
            'allowed_origins' => $spec['domain'] !== null ? ['https://'.$spec['domain']] : [],
            'created_at' => $created,
        ])->save();

        foreach ($spec['log_channels'] as $channel) {
            $project->logChannels()->firstOrCreate(['name' => $channel]);
        }

        // Named here: the factory's unique names would run out across six projects.
        foreach (self::LABELS as $label => $color) {
            Label::query()->create(['project_id' => $project->id, 'name' => $label, 'color' => $color]);
        }

        // The team's agents look after the team's projects.
        McpToken::query()->where('team_id', $team->id)->get()->each(fn (McpToken $token) => $token->projects()->syncWithoutDetaching([$project->id]));

        match ($spec['slug']) {
            'storefront' => $this->storefront($project),
            'orders-api' => $this->ordersApi($project),
            'fulfillment-worker' => $this->worker($project),
            default => null,
        };
    }

    private function storefront(Project $project): void
    {
        ProjectRepository::factory()->for($project)->create([
            'repository' => 'example/storefront',
            'token' => 'github_pat_showcase_placeholder',
            'labels' => ['lumberjack', 'bug'],
        ]);

        // Regressions only page for production; staging is noisy.
        $this->customizeRule->execute($project, AlertRuleType::Regression)->update(['environments' => ['production']]);

        $this->saveRetention->execute(['project_id' => $project->id], RetentionCategory::Logs, 7);
        $this->saveRetention->execute(['project_id' => $project->id, 'job_class' => 'App\\Jobs\\SyncInventory'], RetentionCategory::JobRuns, 3);
        $payments = $project->logChannels()->where('name', 'payments')->sole();
        $this->saveRetention->execute(['project_id' => $project->id, 'log_channel_id' => $payments->id], RetentionCategory::Logs, 30);
    }

    private function ordersApi(Project $project): void
    {
        $this->saveChannel->execute($project->team, ChannelType::Webhook, 'Incident bot', ['url' => 'https://hooks.example.com/lumberjack/orders-api'], project: $project);
    }

    private function worker(Project $project): void
    {
        $this->customizeRule->execute($project, AlertRuleType::QueueBacklog)->update(['settings' => ['size' => 2000, 'age_minutes' => 15, 'sustain_minutes' => 5]]);
        $this->setThreshold->execute($project, 'redis', 'imports', 50, 120, 30, false);
        $this->setThreshold->execute($project, 'redis', 'labels', 1500, 20, 10, false);
    }

    /** The teams' alert channels, placeholders that send nowhere real. */
    private function channels(): void
    {
        $channels = [
            'Local Team' => [
                [ChannelType::Mail, 'On-call inbox', ['address' => 'oncall@example.com'], 'sent'],
                [ChannelType::Discord, '#shop-alerts', ['webhook_url' => 'https://discord.com/api/webhooks/000000000000000000/showcase-placeholder'], 'sent'],
                [ChannelType::Slack, '#ops', ['webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/showcase-placeholder'], 'failed'],
            ],
            'Acme Agency' => [[ChannelType::Mail, 'Acme support', ['address' => 'support@acme.example'], 'sent']],
            'Northwind Labs' => [[ChannelType::Slack, '#northwind-alerts', ['webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000001/showcase-placeholder'], 'sent']],
        ];

        foreach ($channels as $teamName => $list) {
            $team = Team::query()->where('name', $teamName)->first();

            foreach ($team !== null ? $list : [] as [$type, $name, $config, $delivery]) {
                $channel = $team->notificationChannels()->where('name', $name)->first();

                if ($channel === null) {
                    $channel = $this->saveChannel->execute($team, $type, $name, $config);
                    $channel->forceFill(['last_delivery_status' => $delivery, 'last_delivery_at' => Showcase::now()->subMinutes(mt_rand(5, 600))])->save();
                }

                // Again on every run: a showcase project made since gets it too.
                $this->keepToShowcase($team, $channel);
            }
        }
    }

    /**
     * A team with projects of its own (Demo) keeps alerting as it did: the channel leaves
     * the team's rules and goes to the showcase projects' instead, as an add-on where the
     * rule is the team's.
     */
    private function keepToShowcase(Team $team, NotificationChannel $channel): void
    {
        $showcase = $team->projects()->whereIn('slug', array_column(ShowcaseCatalog::projects(), 'slug'))->get();

        if ($team->projects()->count() === $showcase->count()) {
            return;
        }

        $types = array_map(fn (AlertRuleType $type): string => $type->value, array_filter(AlertRuleType::cases(), fn (AlertRuleType $type): bool => $type->enabledByDefault()));
        $team->alertRules()->get()->each(fn (AlertRule $rule) => $rule->channels()->detach($channel->id));

        foreach ($showcase as $project) {
            AlertRule::query()->whereEffectiveFor($project)->whereIn('type', $types)->get()->each(fn (AlertRule $rule) => $rule->project_id !== null
                ? $rule->channels()->syncWithoutDetaching([$channel->id])
                : $rule->addOnsFor($project)->syncWithoutDetaching([$channel->id]));
        }
    }

    /**
     * One policy at each level beside the project ones made with the projects: the
     * application keeps requests a month (the releases page looks that far back), the
     * showcase's own team keeps occurrences half a year. Other teams' settings are not
     * touched.
     */
    private function retention(): void
    {
        if (! RetentionPolicy::query()->whereNull('team_id')->whereNull('project_id')->where('category', RetentionCategory::Requests->value)->exists()) {
            $this->saveRetention->execute([], RetentionCategory::Requests, 30);
        }

        $team = Team::query()->where('name', 'Northwind Labs')->first();

        if ($team !== null && ! $team->retentionPolicies()->whereNull('project_id')->exists()) {
            $this->saveRetention->execute(['team_id' => $team->id], RetentionCategory::Occurrences, 180);
            $this->saveRetention->execute(['team_id' => $team->id], RetentionCategory::Logs, 30);
        }
    }
}
