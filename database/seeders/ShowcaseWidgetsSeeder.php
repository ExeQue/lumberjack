<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Enums\ScreenFit;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Showcase;
use Illuminate\Database\Seeder;

/**
 * The Local Team's "Widget gallery": one widget of every type and its main variants, the
 * screen docs/widgets.md takes its screenshots of (npm run docs:screenshots). A 12 x 12
 * grid that fills its display, so a TV twice as tall as wide shows each tile as large as
 * a 12 x 6 screen would. Its calendar is a data source of Storefront whose feed is
 * written again on every run, relative to now; a gallery screen already there is kept.
 */
class ShowcaseWidgetsSeeder extends Seeder
{
    public const string NAME = 'Widget gallery';

    private const string TIMEZONE = 'Europe/Copenhagen';

    public function run(): void
    {
        $team = Team::query()->where('name', 'Local Team')->first();

        if ($team === null) {
            return;
        }

        $project = $team->projects()->where('slug', 'storefront')->first() ?? $team->projects()->first();
        $calendar = $project !== null ? $this->calendar($project) : null;

        if ($team->screens()->where('name', self::NAME)->exists()) {
            return;
        }

        Screen::factory()->for($team)->create([
            'name' => self::NAME,
            'cols' => 12,
            'rows' => 12,
            'fit' => ScreenFit::Fill,
            'theme' => ScreenTheme::Dark,
            'timezone' => self::TIMEZONE,
            'scope' => ['projects' => [], 'environment' => 'production', 'period' => '24h'],
            'widgets' => $this->widgets($project, $calendar),
        ]);
    }

    /** The calendar the calendar widgets read, its feed made afresh. */
    private function calendar(Project $project): DataSource
    {
        $feed = $this->feed(Showcase::now()->setTimezone(self::TIMEZONE));
        $source = $project->dataSources()->where('name', 'Team calendar')->first();

        if ($source !== null) {
            $source->forceFill(['last_body' => $feed, 'last_ok_at' => Showcase::now(), 'last_error' => null])->save();

            return $source;
        }

        return DataSource::factory()->for($project)->ical($feed)->create(['last_ok_at' => Showcase::now()]);
    }

    /** A week of a team's calendar around now: one meeting on now, a few next, one weekly. */
    private function feed(CarbonImmutable $now): string
    {
        $utc = fn (CarbonImmutable $at): string => $at->utc()->format('Ymd\THis\Z');
        $event = fn (string $uid, string $title, CarbonImmutable $start, CarbonImmutable $end, ?string $location = null): string => implode("\r\n", array_filter([
            'BEGIN:VEVENT',
            "UID:{$uid}@showcase.lumberjack",
            'DTSTAMP:'.$utc($now),
            'DTSTART:'.$utc($start),
            'DTEND:'.$utc($end),
            "SUMMARY:{$title}",
            $location !== null ? "LOCATION:{$location}" : null,
            'END:VEVENT',
        ]));
        $offsite = $now->addDays(4)->startOfDay();
        // Three weeks back on the weekday after tomorrow, so it comes up within the week.
        $planning = $now->addDays(2)->subWeeks(3)->setTime(9, 30);

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Lumberjack//Showcase//EN',
            $event('release-review', 'Release review', $now->subMinutes(15), $now->addMinutes(45), 'Room Oak'),
            $event('support-handover', 'Support handover', $now->addHours(2), $now->addHours(2)->addMinutes(30), 'Room Oak'),
            $event('db-maintenance', 'Orders database maintenance', $now->addDay()->setTime(22, 0), $now->addDay()->setTime(23, 0)),
            // In local time, so it stays at 09:30 across a change to or from summer time.
            implode("\r\n", [
                'BEGIN:VEVENT',
                'UID:weekly-planning@showcase.lumberjack',
                'DTSTAMP:'.$utc($now),
                'DTSTART;TZID='.self::TIMEZONE.':'.$planning->format('Ymd\THis'),
                'DTEND;TZID='.self::TIMEZONE.':'.$planning->addHour()->format('Ymd\THis'),
                'SUMMARY:Weekly planning',
                'LOCATION:Room Birch',
                'RRULE:FREQ=WEEKLY',
                'END:VEVENT',
            ]),
            implode("\r\n", [
                'BEGIN:VEVENT',
                'UID:offsite@showcase.lumberjack',
                'DTSTAMP:'.$utc($now),
                'DTSTART;VALUE=DATE:'.$offsite->format('Ymd'),
                'DTEND;VALUE=DATE:'.$offsite->addDay()->format('Ymd'),
                'SUMMARY:Team offsite',
                'END:VEVENT',
            ]),
            'END:VCALENDAR',
            '',
        ]);
    }

    /**
     * Four bands of tiles on the 12 x 12 grid, named by type and variant.
     *
     * @return list<array<string, mixed>>
     */
    private function widgets(?Project $project, ?DataSource $calendar): array
    {
        $widget = fn (string $id, string $type, ?string $source, int $x, int $y, int $w, int $h, ?string $title = null, array $options = [], array $scope = []): array => [
            'id' => $id, 'type' => $type, 'source' => $source, 'rotate' => [], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'title' => $title, 'options' => $options, 'scope' => $scope,
        ];
        $storefront = $project !== null ? ['projects' => [$project->uuid]] : [];
        $calendarOption = $calendar !== null ? ['data_source' => $calendar->uuid] : [];
        $countdownTarget = Showcase::now()->setTimezone(self::TIMEZONE)->addDays(12)->setTime(9, 0)->format('Y-m-d\TH:i');

        return [
            $widget('stat', 'stat', 'p95', 0, 0, 3, 2, options: ['sparkline' => false]),
            $widget('stat-sparkline', 'stat', 'requests', 3, 0, 3, 2, options: ['compare' => 'yesterday']),
            $widget('stat-release', 'stat', 'current_release', 6, 0, 3, 2, 'Storefront release', ['compare' => 'none', 'sparkline' => false], $storefront),
            $widget('gauge', 'gauge', 'host_cpu', 9, 0, 3, 2),

            $widget('chart-area', 'chart', 'requests_errors', 0, 2, 4, 2, options: ['style' => 'area', 'smooth' => true]),
            $widget('chart-split', 'chart', 'requests', 4, 2, 4, 2, 'Requests by route', ['split_by' => 'route', 'legend' => true], $storefront),
            $widget('chart-bars', 'chart', 'p95', 8, 2, 4, 2, options: ['overlay_previous' => true]),

            $widget('list-issues', 'list', 'latest_issues', 0, 4, 4, 2, options: ['sort' => 'worst', 'show_project' => true, 'limit' => '10']),
            $widget('list-queues', 'list', 'queues', 4, 4, 4, 2, options: ['show_project' => true]),
            $widget('list-calendar', 'list', 'calendar', 8, 4, 4, 2, 'This week', $calendarOption),

            $widget('health-cards', 'health', 'checks', 0, 6, 4, 2, options: ['view' => 'cards']),
            $widget('health-list', 'health', 'checks', 4, 6, 4, 2, 'Storefront checks', ['view' => 'list'], $storefront),
            $widget('overview', 'overview', 'projects', 8, 6, 4, 2),

            $widget('slo-availability', 'slo', 'availability', 0, 8, 3, 2, options: ['slo_target' => '99_5']),
            $widget('slo-latency', 'slo', 'latency', 3, 8, 3, 2),
            $widget('clock-world', 'clock', null, 6, 8, 3, 2, 'Copenhagen', ['date' => true, 'zones' => "America/New_York\nAsia/Tokyo\nAustralia/Sydney"]),
            $widget('countdown', 'countdown', null, 9, 8, 3, 2, options: ['target' => $countdownTarget, 'countdown_label' => 'Release 3.0', 'seconds' => false]),

            $widget('countdown-calendar', 'countdown', null, 0, 10, 3, 2, 'Room Oak', ['counts_to' => 'calendar', 'calendar_text' => 'status', ...$calendarOption]),
            $widget('qr-wifi', 'qr', null, 3, 10, 2, 2, options: ['content' => 'wifi', 'ssid' => 'Office guests', 'password' => 'coffee-and-logs', 'caption' => 'Guest wifi']),
            $widget('media-image', 'media', null, 5, 10, 3, 2, options: ['media_url' => rtrim((string) config('app.url'), '/').'/favicon.svg', 'caption' => 'Lumberjack']),
            $widget('message', 'message', null, 8, 10, 4, 1, 'Heads up', ['message' => 'Deploy freeze from Friday noon until Monday morning.', 'tone' => 'warning']),
            $widget('message-ticker', 'message', null, 8, 11, 4, 1, 'Planned work', ['message' => 'Orders database maintenance tomorrow at 22:00 for an hour. Checkout stays up; order history may be slow.', 'tone' => 'info', 'scroll' => true]),
        ];
    }
}
