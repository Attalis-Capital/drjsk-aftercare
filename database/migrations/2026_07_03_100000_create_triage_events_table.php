<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wound-photo triage audit event (mission #1701, decision D5 alert surfacing v1).
 *
 * A single row is written whenever the ensemble reaches an urgent verdict, so the
 * urgent surfacing is auditable. This is NOT the #1710 clinician dashboard history
 * (that scope is deliberately not pulled forward). No PHI and no image bytes are
 * stored here: only the visit/document references, the verdict, the OR-gate
 * reason, per-voter class+confidence, and the trace id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained()->nullOnDelete();

            // Ensemble verdict: 'urgent' | 'needs-review' (two-valued, D3).
            $table->string('verdict', 20);
            // OR-gate reason, e.g. or_gate_escalation / both_calls_failed_unavailable.
            $table->string('reason', 64);
            // Which signals forced escalation (JSON array of tags). No free text.
            $table->json('escalated_by')->nullable();
            // Triage unavailable (both voters failed) path flag.
            $table->boolean('unavailable')->default(false);

            // Per-voter metadata only: model alias, class, confidence, ok. No
            // rationale, no image, no patient identifiers.
            $table->string('primary_model', 64)->nullable();
            $table->string('primary_class', 20)->nullable();
            $table->float('primary_confidence')->nullable();
            $table->boolean('primary_ok')->default(false);
            $table->string('secondary_model', 64)->nullable();
            $table->string('secondary_class', 20)->nullable();
            $table->float('secondary_confidence')->nullable();
            $table->boolean('secondary_ok')->default(false);

            // Configured operating point at decision time (confidence floor).
            $table->float('confidence_floor')->nullable();

            // Langfuse trace id (self-hosted Railway) if tracing is enabled.
            $table->string('trace_id', 64)->nullable();

            $table->timestamps();

            $table->index('visit_id');
            $table->index('verdict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_events');
    }
};
