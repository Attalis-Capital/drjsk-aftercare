<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Guards the unauthenticated, destructive /demo/reset endpoint (F1, #1850).
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

        $response = $this->postJson('/api/demo/reset');

        $response->assertStatus(403)
            ->assertJsonPath('error.message', 'Demo reset is not enabled in this environment.');
    }

    public function test_reset_is_forbidden_when_flag_false(): void
    {
        config()->set('demo.reset_enabled', false);
        Artisan::shouldReceive('call')->never();

        $response = $this->postJson('/api/demo/reset');

        $response->assertStatus(403)
            ->assertJsonPath('error.message', 'Demo reset is not enabled in this environment.');
    }

    public function test_reset_executes_when_flag_true_in_testing_env(): void
    {
        config()->set('demo.reset_enabled', true);

        // Assert the destructive command is invoked exactly once when enabled,
        // without actually running migrate:fresh against the test DB.
        Artisan::shouldReceive('call')->once()->with('migrate:fresh');

        $response = $this->postJson('/api/demo/reset');

        $response->assertStatus(200)
            ->assertJsonPath('data.message', 'Demo data has been reset successfully.');
    }
}
