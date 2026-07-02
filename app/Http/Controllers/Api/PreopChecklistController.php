<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PreopChecklistOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Serves and edits the GENERIC pre-operative checklist templates.
 *
 * Templates are defined in config/preop-checklists.php and are the same for
 * every patient having a given procedure. They are NOT AI-personalised. The
 * practice can edit a template via the admin editor (update()), which stores
 * an override row; patients read the (possibly overridden) template and tick
 * items off locally on their own device.
 */
class PreopChecklistController extends Controller
{
    /**
     * List all procedures with their (possibly overridden) templates.
     */
    public function index(): JsonResponse
    {
        $templates = collect(config('preop-checklists.templates', []))
            ->map(fn (array $t) => $this->resolve($t['key'], $t))
            ->values();

        return response()->json([
            'data' => [
                'practice' => config('preop-checklists.practice'),
                'templates' => $templates,
            ],
        ]);
    }

    /**
     * Show a single procedure's template.
     */
    public function show(string $procedure): JsonResponse
    {
        $base = config("preop-checklists.templates.{$procedure}");

        if (! $base) {
            return response()->json([
                'error' => ['message' => 'Unknown procedure checklist.'],
            ], 404);
        }

        return response()->json([
            'data' => [
                'practice' => config('preop-checklists.practice'),
                'template' => $this->resolve($procedure, $base),
            ],
        ]);
    }

    /**
     * Admin: replace the generic template for a procedure.
     *
     * Role-protected at the route level (doctor/admin). Stores the edited
     * template as an override. Does not touch any patient or FHIR data.
     */
    public function update(Request $request, string $procedure): JsonResponse
    {
        $base = config("preop-checklists.templates.{$procedure}");

        if (! $base) {
            return response()->json([
                'error' => ['message' => 'Unknown procedure checklist.'],
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'summary' => ['nullable', 'string', 'max:500'],
            'sections' => ['required', 'array'],
            'sections.*.title' => ['required', 'string', 'max:150'],
            'sections.*.items' => ['required', 'array'],
            'sections.*.items.*.label' => ['required', 'string', 'max:500'],
            'sections.*.items.*.link' => ['nullable', 'string', 'max:500'],
        ]);

        $template = [
            'key' => $procedure,
            'name' => $validated['name'],
            'summary' => $validated['summary'] ?? ($base['summary'] ?? ''),
            'sections' => $validated['sections'],
        ];

        $override = PreopChecklistOverride::updateOrCreate(
            ['procedure_key' => $procedure],
            ['template' => $template],
        );

        return response()->json([
            'data' => [
                'template' => $override->template,
            ],
        ]);
    }

    /**
     * Merge the config template with any stored admin override.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function resolve(string $procedure, array $base): array
    {
        if (! Schema::hasTable('preop_checklist_overrides')) {
            return $base;
        }

        $override = PreopChecklistOverride::where('procedure_key', $procedure)->first();

        return $override ? $override->template : $base;
    }
}
