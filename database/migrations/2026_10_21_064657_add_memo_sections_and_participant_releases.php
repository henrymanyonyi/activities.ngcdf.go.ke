<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generated activity memo (risks and any extra sections the template does
 * not cover) and officer releases: a recall, release or reassignment from an
 * activity part-way through, kept beside the original assignment and its
 * cost effect. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->text('risks')->nullable()->after('justification');
            // [{heading, body}, ...] after the template's own sections.
            $table->json('memo_sections')->nullable()->after('risks');
        });

        Schema::create('participant_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->restrictOnDelete();
            $table->foreignId('activity_participant_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('kind', 20);
            $table->text('reason');
            // The assignment as it stood before the release.
            $table->date('original_start_date');
            $table->date('original_end_date');
            // Last day on the activity; the officer is free from the next day.
            $table->date('last_day');
            $table->foreignId('destination_activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->string('initiated_by_name');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // The officer's own planned cost and the activity's planned total, before and after.
            $table->decimal('participant_cost_before', 15, 2);
            $table->decimal('participant_cost_after', 15, 2);
            $table->decimal('activity_total_before', 15, 2);
            $table->decimal('activity_total_after', 15, 2);
            // Approved total at the time, the baseline the adjustment is measured against.
            $table->decimal('approved_total', 15, 2)->nullable();
            // Per cost line: before, after, treatment and any reason.
            $table->json('lines');
            $table->timestamps();

            $table->index(['staff_id', 'last_day']);
            $table->index(['activity_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_releases');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['risks', 'memo_sections']);
        });
    }
};
