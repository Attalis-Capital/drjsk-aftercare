<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-edited version of a generic pre-operative checklist template.
 *
 * The template is stored as JSON, keyed by procedure. This is NOT a FHIR model
 * and is NOT patient-specific - it holds the practice-wide generic template only.
 */
class PreopChecklistOverride extends Model
{
    use HasUuids;

    protected $fillable = [
        'procedure_key',
        'template',
    ];

    protected function casts(): array
    {
        return [
            'template' => 'array',
        ];
    }
}
