<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Demo\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Guards the unauthenticated, destructive /api/v1/demo/reset endpoint (F1, #1850).
 *
 * reset() runs migrate:fresh (full DB wipe). The route is unauthenticated and
 * sits outside throttle:demo, so it must execute ONLY when explicitly enabled
 * via the DEMO_RESET_ENABLED flag (config('demo.reset_enabled')), and never in
 * production regardless of the flag.
 */
class DemoResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_is_forbidden_when_flag_absent(): void
    {
        // No explicit config — default is false.
        Artisan::shouldReceive('call')->never();

        $response = $this->postJson('/api/v1/demo/reset');

        $response->assertStatus(403)
            ->assertJsonPath('error.message', 'Demo reset is not enabled in this environment.');
    }

    public function test_reset_is_forbidden_when_flag_false(): void
    {
        config()->set('demo.reset_enabled', false);
        Artisan::shouldReceive('call')->never();

        $response = $this->postJson('/api/v1/demo/reset');

        $response->assertStatus(403)
            ->assertJsonPath('error.message', 'Demo reset is not enabled in this environment.');
    }

    public function test_reset_executes_when_flag_true_in_testing_env(): void
    {
        config()->set('demo.reset_enabled', true);

        // Assert the destructive command is invoked exactly once when enabled,
        // without actually running migrate:fresh against the test DB. The seeder
        // is mocked so the test isolates the guard + Artisan dispatch (the
        // security-relevant behaviour) from demo-seed data specifics.
        Artisan::shouldReceive('call')->once()->with('migrate:fresh');
        // seed() is typed to return App\Models\User; return an unsaved instance
        // (no DB write needed) so the mock satisfies the declared return type.
        $this->mock(DemoScenarioSeeder::class, function ($m) {
            $m->shouldReceive('seed')->andReturn(User::factory()->make());
        });

        $response = $this->postJson('/api/v1/demo/reset');

        $response->assertStatus(200)
            ->assertJsonPath('data.message', 'Demo data has been reset successfully.');
    }
}
