<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission #1718 B1: close the clinical loop.
 *
 * Additive, non-destructive migration. Adds a nullable `severity` column to the
 * existing notifications table so urgent clinical alerts (urgent triage verdicts
 * and critical chat escalations) can be pinned at the top of the doctor
 * dashboard alert panel. No data is altered or removed; down() drops only the
 * column this migration adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('severity')->nullable()->after('type');
            $table->index(['user_id', 'type', 'severity'], 'notifications_user_type_severity_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_type_severity_index');
            $table->dropColumn('severity');
        });
    }
};
