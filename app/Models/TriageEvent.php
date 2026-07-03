<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record of a wound-photo triage decision (mission #1701, D5).
 *
 * Holds NO PHI and NO image bytes - only references, the verdict, the OR-gate
 * reason, and per-voter class/confidence/ok metadata. This is the audit event,
 * not the #1710 clinician dashboard history.
 */
class TriageEvent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'visit_id',
        'document_id',
        'verdict',
        'reason',
        'escalated_by',
        'unavailable',
        'primary_model',
        'primary_class',
        'primary_confidence',
        'primary_ok',
        'secondary_model',
        'secondary_class',
        'secondary_confidence',
        'secondary_ok',
        'confidence_floor',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'escalated_by' => 'array',
            'unavailable' => 'boolean',
            'primary_confidence' => 'float',
            'primary_ok' => 'boolean',
            'secondary_confidence' => 'float',
            'secondary_ok' => 'boolean',
            'confidence_floor' => 'float',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
