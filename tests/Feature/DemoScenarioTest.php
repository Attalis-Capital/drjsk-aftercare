<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Demo scenario tests (mission #1709).
 *
 * The legacy cardiology/multi-specialty DemoSeeder and its 12 visit-0x dirs were
 * retired; the three plastic-surgery scenarios in config/demo-scenarios.php
 * (materialised on demand by DemoScenarioSeeder) are the only reachable demo
 * content. These tests assert that path end-to-end.
 */
class DemoScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_demo_scenarios(): void
    {
        $response = $this->getJson('/api/v1/demo/scenarios');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.key', 'diep-flap')
            ->assertJsonPath('data.0.name', 'DIEP Flap Reconstruction')
            ->assertJsonPath('data.0.patient_name', 'Helen Whitfield')
            ->assertJsonPath('data.0.specialty', 'plastic_surgery')
            ->assertJsonPath('data.1.key', 'abdominoplasty')
            ->assertJsonPath('data.2.key', 'breast-reduction');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function plasticScenarios(): array
    {
        // key => [scenario key, patient first name, patient last name]
        return [
            'DIEP flap reconstruction' => ['diep-flap', 'Helen', 'Whitfield'],
            'abdominoplasty' => ['abdominoplasty', 'Sophie', 'Marchetti'],
            'breast reduction' => ['breast-reduction', 'Priya', 'Ramanathan'],
        ];
    }

    #[DataProvider('plasticScenarios')]
    public function test_can_start_each_plastic_scenario(string $scenario, string $first, string $last): void
    {
        $response = $this->postJson('/api/v1/demo/start-scenario', [
            'scenario' => $scenario,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['user', 'token', 'visit', 'scenario'],
            ])
            ->assertJsonPath('data.user.role', 'patient')
            ->assertJsonPath('data.scenario', $scenario);

        $this->assertDatabaseHas('patients', [
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    public function test_generic_start_seeds_default_plastic_scenario(): void
    {
        $response = $this->postJson('/api/v1/demo/start', []);

        $response->assertOk()
            ->assertJsonPath('data.role', 'patient')
            ->assertJsonPath('data.scenario', 'diep-flap');

        // Default scenario patient is materialised.
        $this->assertDatabaseHas('patients', [
            'first_name' => 'Helen',
            'last_name' => 'Whitfield',
        ]);
    }

    public function test_start_scenario_validates_input(): void
    {
        $response = $this->postJson('/api/v1/demo/start-scenario', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('scenario');
    }

    public function test_start_scenario_rejects_unknown_scenario(): void
    {
        $response = $this->postJson('/api/v1/demo/start-scenario', [
            'scenario' => 'nonexistent',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.message', 'Unknown scenario: nonexistent');
    }

    public function test_same_scenario_reuses_existing_user(): void
    {
        $r1 = $this->postJson('/api/v1/demo/start-scenario', ['scenario' => 'diep-flap']);
        $r2 = $this->postJson('/api/v1/demo/start-scenario', ['scenario' => 'diep-flap']);

        $this->assertEquals(
            $r1->json('data.user.id'),
            $r2->json('data.user.id'),
        );
    }

    public function test_scenario_creates_visit_note_with_patient_visit(): void
    {
        $response = $this->postJson('/api/v1/demo/start-scenario', ['scenario' => 'diep-flap']);

        $response->assertOk();
        $this->assertNotNull($response->json('data.visit'), 'Expected a seeded visit for the scenario');
    }

    public function test_single_surgeon_across_scenarios(): void
    {
        // All plastic scenarios map to the single practice surgeon; starting two
        // scenarios must not create competing default-doctor personas.
        $this->postJson('/api/v1/demo/start-scenario', ['scenario' => 'diep-flap']);
        $this->postJson('/api/v1/demo/start-scenario', ['scenario' => 'abdominoplasty']);

        $doctorEmail = config('demo-scenarios.doctor.email');
        $this->assertDatabaseHas('users', [
            'email' => $doctorEmail,
            'role' => 'doctor',
        ]);
        $this->assertSame(
            1,
            \App\Models\User::where('role', 'doctor')->count(),
            'Expected exactly one demo surgeon across scenarios'
        );
    }
}
