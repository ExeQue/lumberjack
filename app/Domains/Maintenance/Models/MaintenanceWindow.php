<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Models;

use App\Domains\Maintenance\Eloquent\Builders\MaintenanceWindowBuilder;
use App\Domains\Maintenance\Enums\MaintenanceSource;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of one environment of a project under maintenance, planned ahead or reported
 * by the app. Only an end event or someone ending it closes it, never time: past its
 * expected end it is overrun, and the maintenance overrun rule says so.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $environment empty for every environment of the project
 * @property MaintenanceSource $source
 * @property string|null $message
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $expected_end_at the planned end, the app's, or the project's default after the start
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $overrun_alerted_at the last overrun alert sent
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'environment', 'source', 'message', 'starts_at', 'expected_end_at', 'ended_at', 'overrun_alerted_at'])]
#[UseEloquentBuilder(MaintenanceWindowBuilder::class)]
#[UseFactory(MaintenanceWindowFactory::class)]
class MaintenanceWindow extends Model
{
    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'source' => MaintenanceSource::class,
            'starts_at' => 'datetime',
            'expected_end_at' => 'datetime',
            'ended_at' => 'datetime',
            'overrun_alerted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The window open now (or at $at) that covers the environment; the latest when several do.
     */
    public static function openFor(Project|int $project, ?string $environment, ?CarbonImmutable $at = null): ?self
    {
        return self::query()
            ->whereProject($project)
            ->whereOpen($at)
            ->whereCovers($environment)
            ->latest('starts_at')
            ->first();
    }

    /**
     * Moves the expected end. One moved past now is a new promise: the overrun alerts start over.
     */
    public function expectTo(CarbonImmutable $end): void
    {
        $this->expected_end_at = $end->max($this->starts_at);

        if ($this->expected_end_at->greaterThan(now())) {
            $this->overrun_alerted_at = null;
        }
    }

    /**
     * Whether it was open at the moment for the environment, as whereOpen() and whereCovers() ask.
     */
    public function covers(?string $environment, CarbonInterface $at): bool
    {
        return $this->starts_at->lessThanOrEqualTo($at)
            && ($this->ended_at === null || $this->ended_at->greaterThan($at))
            && ($environment === null || $environment === '' || in_array($this->environment, [$environment, ''], true));
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null && $this->starts_at->lessThanOrEqualTo(now());
    }

    public function isOverrun(): bool
    {
        return $this->ended_at === null && $this->expected_end_at->lessThanOrEqualTo(now());
    }

    /**
     * "open", "overrun", "planned" (not started yet) or "ended".
     */
    public function state(): string
    {
        return match (true) {
            $this->ended_at !== null => 'ended',
            $this->starts_at->greaterThan(now()) => 'planned',
            $this->isOverrun() => 'overrun',
            default => 'open',
        };
    }

    /**
     * Where uptime stops leaving its downtime out: the expected end, or the end when it came sooner.
     */
    public function excusedUntil(): CarbonImmutable
    {
        return $this->ended_at !== null ? $this->ended_at->min($this->expected_end_at) : $this->expected_end_at->min(now());
    }

    /**
     * The window as the pages, the screens and MCP show it.
     *
     * @return array{id: string, environment: string|null, source: string, message: string|null, starts_at: string, expected_end_at: string, ended_at: string|null, state: string}
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->uuid,
            'environment' => $this->environment !== '' ? $this->environment : null,
            'source' => $this->source->value,
            'message' => $this->message,
            'starts_at' => $this->starts_at->toIso8601String(),
            'expected_end_at' => $this->expected_end_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'state' => $this->state(),
        ];
    }
}
