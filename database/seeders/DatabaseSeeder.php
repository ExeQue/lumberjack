<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A fresh install for development, tests by hand and screenshots: the Local Team
 * with Demo and its fixed keys (LocalSeeder), then the showcase projects full of
 * traffic, issues, jobs and checks (ShowcaseSeeder).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([LocalSeeder::class, ShowcaseSeeder::class]);
    }
}
