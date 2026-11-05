<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Support\Showcase;
use Illuminate\Database\Seeder;

/**
 * The showcase: three teams, six projects (a webshop, a SaaS API, a marketing site, a
 * background worker and two smaller client projects) with a month of releases and
 * traffic, issues, jobs, schedules, health checks, logs, alerts and screens, all
 * relative to now and the same on every run. It only adds: run it on a database in
 * use (db:seed --class=ShowcaseSeeder) and it fills the showcase projects it creates,
 * leaving everything else, the showcase projects already there included, as it was.
 */
class ShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        Showcase::start();

        // Seeding is no change anyone watches live: tell no browser about it.
        config(['broadcasting.default' => 'null']);

        $this->call([
            ShowcaseTeamsSeeder::class,
            ShowcaseTrafficSeeder::class,
            ShowcaseJobsSeeder::class,
            ShowcaseIssuesSeeder::class,
            ShowcaseHealthSeeder::class,
            ShowcaseLogsSeeder::class,
            ShowcaseAlertsSeeder::class,
            ShowcaseScreensSeeder::class,
            ShowcaseWidgetsSeeder::class,
        ]);

        $this->command?->info('Showcase people (password "password"): '.implode(', ', array_merge(...array_map(
            fn (array $people): array => array_column($people, 'email'),
            array_values(Support\ShowcaseCatalog::teams()),
        ))));
    }
}
