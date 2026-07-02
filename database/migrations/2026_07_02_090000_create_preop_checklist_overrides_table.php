<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores admin-edited overrides for the generic pre-operative checklist
 * templates. Templates ship in config/preop-checklists.php; when the practice
 * edits a template through the admin editor, the edited version is stored here
 * keyed by procedure. These are generic templates (NOT AI-personalised and NOT
 * patient-specific), so there is no link to a patient or any FHIR model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preop_checklist_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('procedure_key', 60)->unique();
            $table->jsonb('template');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preop_checklist_overrides');
    }
};
