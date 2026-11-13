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
 * screen docs/widgets.md takes its screenshots of (npm run docs:screenshots). An 8 x 17
 * grid that fills its display: 1920 px wide, its cells are as wide as an 8-column screen's
 * on a 1920 x 1080 TV, and square. Its calendar is a data source of Storefront whose feed is
 * written again on every run, relative to now; a gallery screen already there is kept.
 */
class ShowcaseWidgetsSeeder extends Seeder
{
    public const string NAME = 'Widget gallery';

    /** Eight columns as on a 1920 x 1080 TV with four rows; the gallery has more rows of the same size. */
    private const int COLS = 8;

    private const int ROWS = 17;

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
            'cols' => self::COLS,
            'rows' => self::ROWS,
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
     * Rows of tiles on the 8 x 17 grid, named by type and variant.
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
        // One check Lumberjack runs itself, so it stays fresh.
        $check = $project?->healthChecks()->where('name', 'Storefront')->value('uuid');
        $single = $check !== null ? ['checks' => [$check]] : [];
        $plain = ['sparkline' => false];
        $calendarOption = $calendar !== null ? ['data_source' => $calendar->uuid] : [];
        // Text widgets in the middle of their tile, as most screens show them.
        $centered = ['align' => 'center', 'valign' => 'middle'];
        $countdownTarget = Showcase::now()->setTimezone(self::TIMEZONE)->addDays(12)->setTime(9, 0)->format('Y-m-d\TH:i');

        return [
            $widget('stat', 'stat', 'p95', 0, 0, 1, 1, options: ['sparkline' => false, ...$centered]),
            $widget('stat-sparkline', 'stat', 'requests', 3, 0, 2, 1, options: ['compare' => 'yesterday', 'align' => 'center']),
            $widget('stat-release', 'stat', 'current_release', 1, 0, 1, 1, 'Storefront release', ['compare' => 'none', 'sparkline' => false, ...$centered], $storefront),
            $widget('gauge', 'gauge', 'host_cpu', 2, 0, 1, 1),

            $widget('chart-area', 'chart', 'requests_errors', 0, 1, 4, 1, options: ['style' => 'area', 'smooth' => true]),
            $widget('chart-bars', 'chart', 'p95', 4, 1, 4, 1, options: ['overlay_previous' => true]),
            $widget('chart-split', 'chart', 'requests', 0, 2, 4, 2, 'Requests by route', ['split_by' => 'route', 'legend' => true], $storefront),
            $widget('list-issues', 'list', 'latest_issues', 4, 2, 4, 2, options: ['sort' => 'worst', 'show_project' => true, 'limit' => '10']),

            $widget('list-queues', 'list', 'queues', 0, 4, 4, 2, options: ['show_project' => true]),
            $widget('list-calendar', 'list', 'calendar', 4, 4, 4, 2, 'This week', $calendarOption),

            $widget('health-cards', 'health', 'checks', 0, 6, 4, 2, options: ['view' => 'cards']),
            $widget('health-list', 'health', 'checks', 4, 6, 4, 2, 'Storefront checks', ['view' => 'list'], $storefront),

            $widget('overview', 'overview', 'projects', 0, 8, 4, 2),
            $widget('health-single', 'health', 'checks', 4, 8, 2, 1, options: [...$single, ...$centered], scope: $storefront),
            $widget('countdown', 'countdown', null, 4, 9, 2, 1, options: ['target' => $countdownTarget, 'countdown_label' => 'Release 3.0', 'seconds' => false, ...$centered]),
            $widget('clock-world', 'clock', null, 6, 8, 2, 2, 'Copenhagen', ['date' => true, 'zones' => "America/New_York\nAsia/Tokyo\nAustralia/Sydney", ...$centered]),

            $widget('slo-availability', 'slo', 'availability', 5, 0, 1, 1, options: ['slo_target' => '99_5', ...$centered]),
            $widget('slo-latency', 'slo', 'latency', 6, 0, 1, 1, options: $centered),
            $widget('countdown-calendar', 'countdown', null, 0, 10, 2, 1, 'Room Oak', ['counts_to' => 'calendar', 'calendar_text' => 'status', ...$calendarOption, ...$centered]),
            $widget('qr-wifi', 'qr', null, 7, 0, 1, 1, options: ['content' => 'wifi', 'ssid' => 'Office guests', 'password' => 'coffee-and-logs', 'caption' => 'Guest wifi']),
            $widget('media-image', 'media', null, 2, 10, 2, 1, options: ['media_url' => rtrim((string) config('app.url'), '/').'/favicon.svg', 'caption' => 'Lumberjack']),

            $widget('message', 'message', null, 4, 10, 4, 1, 'Heads up', ['message' => 'Deploy freeze from Friday noon until Monday morning.', 'tone' => 'warning', ...$centered]),
            $widget('message-ticker', 'message', null, 0, 11, 8, 1, 'Planned work', ['message' => 'Orders database maintenance tomorrow at 22:00 for an hour. Checkout stays up; order history may be slow.', 'tone' => 'info', 'scroll' => true]),

            // The display settings, each against the plain p95 stat or health check it changes.
            $widget('display-default', 'stat', 'p95', 0, 12, 1, 1, options: $plain),
            $widget('display-centered', 'stat', 'p95', 1, 12, 1, 1, options: [...$plain, ...$centered]),
            $widget('display-text-s', 'stat', 'p95', 2, 12, 1, 1, options: [...$plain, 'text_size' => 's']),
            $widget('display-title-l', 'stat', 'p95', 3, 12, 1, 1, options: [...$plain, 'title_size' => 'l']),
            $widget('display-no-title', 'stat', 'p95', 4, 12, 1, 1, options: [...$plain, 'show_title' => false]),
            $widget('display-no-period', 'stat', 'p95', 5, 12, 1, 1, options: [...$plain, 'show_period' => false]),
            $widget('display-no-colors', 'stat', 'p95', 6, 12, 1, 1, options: [...$plain, 'status_colors' => false]),
            $widget('display-ok', 'health', 'checks', 0, 13, 2, 1, options: $single, scope: $storefront),
            $widget('display-no-ok-border', 'health', 'checks', 2, 13, 2, 1, options: [...$single, 'ok_border' => false], scope: $storefront),
            $widget('display-no-card', 'health', 'checks', 4, 13, 2, 1, options: [...$single, 'card' => false], scope: $storefront),

            // The storefront's activity, from ShowcaseActivitySeeder.
            $widget('chart-activity', 'chart', 'activity', 0, 14, 4, 1, 'Storefront activity', ['split_by' => 'activity', 'style' => 'line', 'legend' => true], $storefront),
            $widget('gauge-activity', 'gauge', 'activity', 4, 14, 1, 1, 'Orders placed', ['max' => 200, 'red' => 100, 'amber' => 150], [...$storefront, 'filter' => 'billing:order.placed']),
            $widget('stat-activity', 'stat', 'activity', 5, 14, 2, 1, 'Carts abandoned', ['compare' => 'yesterday', ...$centered], [...$storefront, 'filter' => 'cart:cart.abandoned']),
            $widget('list-activity', 'list', 'top_activity', 0, 15, 4, 2, 'Storefront activity', ['show_project' => false], $storefront),
            $widget('list-activity-latest', 'list', 'latest_activity', 4, 15, 4, 2, options: [], scope: $storefront),
        ];
    }
}
