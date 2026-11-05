<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Support\Ical;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\WidgetSchema;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\ShowcaseWidgetsSeeder;
use Database\Seeders\Support\Showcase;
use Illuminate\Support\Facades\Validator;

covers(ShowcaseWidgetsSeeder::class);

describe('the widget gallery seed', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));
        Showcase::start();
        $this->team = Team::factory()->create(['name' => 'Local Team']);
        $this->project = Project::factory()->for($this->team)->create(['slug' => 'storefront']);
    });

    it('adds a gallery whose every widget the designer would save', function () {
        $this->seed(ShowcaseWidgetsSeeder::class);

        $screen = $this->team->screens()->where('name', ShowcaseWidgetsSeeder::NAME)->sole();
        $projects = [(string) $this->project->uuid];

        expect(collect($screen->widgets)->pluck('type')->unique()->sort()->values()->all())
            ->toBe(collect(WidgetSchema::types())->reject(fn (string $type): bool => $type === 'empty')->sort()->values()->all());

        foreach ($screen->widgets as $widget) {
            $validator = Validator::make(['widget' => $widget], WidgetSchema::rules('widget', $widget, $screen->cols, $screen->rows, $projects));

            expect($validator->errors()->all())->toBe([], "{$widget['id']} does not validate");
        }
    });

    it('gives the calendar widgets a feed with an event on now and more to come', function () {
        $this->seed(ShowcaseWidgetsSeeder::class);

        $calendar = $this->project->dataSources()->sole();
        $events = Ical::events((string) $calendar->last_body, now()->toImmutable()->startOfDay(), now()->toImmutable()->addDays(7));
        $widgets = collect($this->team->screens()->sole()->widgets)->keyBy('id');

        expect($events)->toHaveCount(5)
            ->and(collect($events)->filter(fn (array $event): bool => $event['start'] <= now()->timestamp && now()->timestamp < $event['end'])->pluck('title')->all())->toBe(['Release review'])
            ->and($widgets['list-calendar']['options']['data_source'])->toBe($calendar->uuid)
            ->and($widgets['countdown-calendar']['options']['data_source'])->toBe($calendar->uuid);
    });

    it('keeps the gallery when run again and writes the calendar afresh', function () {
        $this->seed(ShowcaseWidgetsSeeder::class);
        $screen = Screen::query()->sole();

        $this->travel(2)->days();
        Showcase::start();
        $this->seed(ShowcaseWidgetsSeeder::class);

        $calendar = DataSource::query()->sole();
        $events = Ical::events((string) $calendar->last_body, now()->toImmutable()->subMinute(), now()->toImmutable()->addMinute());

        expect(Screen::query()->sole()->is($screen))->toBeTrue()
            ->and(array_column($events, 'title'))->toBe(['Release review']);
    });
});
