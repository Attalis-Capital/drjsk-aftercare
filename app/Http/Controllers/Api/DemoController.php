<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Visit;
use App\Services\Demo\DemoScenarioSeeder;
use App\Services\SlackAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

class DemoController extends Controller
{
    /**
     * Default plastic-surgery scenario used by the generic start/reset/alert
     * endpoints. The authoritative demo content lives in config/demo-scenarios.php
     * and is materialised by DemoScenarioSeeder (mission #1709 retired the legacy
     * cardiology DemoSeeder). Per-scenario entry is via DemoScenarioController.
     */
    private const DEFAULT_SCENARIO = 'diep-flap';

    public function __construct(private DemoScenarioSeeder $seeder)
    {
        if (! app()->environment('local', 'staging', 'testing', 'production')) {
            abort(403, 'Demo endpoints are only available in local/staging environments.');
        }
    }

    /**
     * Resolve the default scenario config or fail loudly if it is missing.
     *
     * @return array<string, mixed>
     */
    private function defaultScenario(): array
    {
        $scenarios = config('demo-scenarios.scenarios', []);

        return $scenarios[self::DEFAULT_SCENARIO] ?? [];
    }

    public function start(Request $request): JsonResponse
    {
        $role = $request->input('role', 'patient');

        $scenario = $this->defaultScenario();
        if ($scenario === []) {
            return response()->json([
                'error' => ['message' => 'Default demo scenario is not configured.'],
            ], 500);
        }

        // Materialise the default plastic-surgery scenario on demand via the
        // authoritative seeder (idempotent per scenario key).
        $patientUser = $this->seeder->seed($scenario);

        // The generic start endpoint can log in as either the seeded patient or
        // the scenario's treating doctor.
        $user = $role === 'doctor'
            ? ($patientUser->patient?->visits()->latest('started_at')->first()?->practitioner?->user ?? $patientUser)
            : $patientUser;

        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $token = $user->createToken('demo-token')->plainTextToken;

        $visit = Visit::where('patient_id', $patientUser->patient_id)
            ->orWhere('created_by', $patientUser->id)
            ->with(['patient:id,first_name,last_name', 'practitioner:id,first_name,last_name'])
            ->latest('started_at')
            ->first();

        return response()->json([
            'data' => [
                'user' => $user,
                'token' => $token,
                'visit' => $visit,
                'role' => $role,
                'scenario' => self::DEFAULT_SCENARIO,
            ],
        ]);
    }

    public function status(): JsonResponse
    {
        $doctorEmail = config('demo-scenarios.doctor.email');
        $hasDemoData = User::where('role', 'patient')
            ->whereNotNull('demo_scenario_key')
            ->exists();

        return response()->json([
            'data' => [
                'seeded' => $hasDemoData,
                'doctor_email' => $doctorEmail,
                'default_scenario' => self::DEFAULT_SCENARIO,
                'password' => 'password',
            ],
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        // Block in production — this wipes the entire database. This hard-block
        // applies regardless of the DEMO_RESET_ENABLED flag below.
        if (app()->environment('production')) {
            SlackAlertService::resetAttempt($request->ip());

            return response()->json([
                'error' => ['message' => 'Demo reset is disabled in production.'],
            ], 403);
        }

        // The route is unauthenticated and destructive (migrate:fresh wipes the
        // DB), so it executes only when explicitly enabled for this environment.
        // Config-wired (not env() at runtime) so it respects config:cache.
        if (! config('demo.reset_enabled')) {
            return response()->json([
                'error' => ['message' => 'Demo reset is not enabled in this environment.'],
            ], 403);
        }

        Artisan::call('migrate:fresh');
        $scenario = $this->defaultScenario();
        if ($scenario !== []) {
            $this->seeder->seed($scenario);
        }

        return response()->json([
            'data' => ['message' => 'Demo data has been reset successfully.'],
        ]);
    }

    public function simulateAlert(): JsonResponse
    {
        $doctorEmail = config('demo-scenarios.doctor.email');
        $doctorUser = User::where('email', $doctorEmail)->first();

        if (! $doctorUser) {
            return response()->json(['error' => ['message' => 'Demo data not seeded']], 404);
        }

        $visit = Visit::latest('started_at')->first();

        $doctorUser->notifications()->create([
            'visit_id' => $visit?->id,
            'type' => 'escalation_alert',
            'title' => 'Patient Escalation Alert',
            'body' => 'Patient reported concerning symptoms that may require immediate attention: breathing difficulty and chest pain (possible pulmonary embolism) since surgery.',
            'data' => [
                'severity' => 'critical',
                'trigger' => 'simulated',
            ],
        ]);

        return response()->json([
            'data' => ['message' => 'Escalation alert simulated successfully.'],
        ]);
    }
}
