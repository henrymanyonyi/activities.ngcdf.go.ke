<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CEO may instruct any department on an approved activity, not only
 * Finance and Procurement (26 October 2026): one instruction of each kind per
 * department, so an activity can carry an "other" instruction to several
 * departments. The new unique index is added before the old one is dropped,
 * so activity_id keeps an index for its foreign key throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_instructions', function (Blueprint $table) {
            $table->unique(['activity_id', 'kind', 'department_id'], 'action_instructions_activity_kind_department_unique');
        });
        Schema::table('action_instructions', function (Blueprint $table) {
            $table->dropUnique(['activity_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('action_instructions', function (Blueprint $table) {
            $table->unique(['activity_id', 'kind']);
        });
        Schema::table('action_instructions', function (Blueprint $table) {
            $table->dropUnique('action_instructions_activity_kind_department_unique');
        });
    }
};
