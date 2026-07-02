<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Demo content is materialised on demand from the authoritative
     * config/demo-scenarios.php via App\Services\Demo\DemoScenarioSeeder
     * (invoked by the demo endpoints), so there is no global seed to run.
     * The legacy cardiology DemoSeeder was retired in mission #1709.
     */
    public function run(): void
    {
        //
    }
}
