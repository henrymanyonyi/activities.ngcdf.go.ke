<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activities (each one also the activity memo) and everything that hangs off
 * them: locations, team, costs, documents, status history, CEO decisions,
 * directives, the back-to-office report, amendments and review comments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->nullable()->unique();
            $table->string('title');
            $table->text('purpose');
            $table->text('expected_outputs')->nullable();
            $table->text('justification')->nullable();
            $table->text('risks')->nullable();
            // [{heading, body}, ...] after the memo template's own sections.
            $table->json('memo_sections')->nullable();
            // The department's own estimate, before the Office of the CEO costs the team.
            $table->decimal('requested_budget', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('activity_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('activity_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organising_department_id')->nullable()->constrained('departments')->nullOnDelete();
            // The Head of Department responsible; created_by is who actually prepared it.
            $table->foreignId('responsible_hod_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_hod_name')->nullable();
            $table->string('target_audience')->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->date('source_received_on')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days')->default(1);
            $table->unsignedSmallInteger('nights')->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('status_reason', 1000)->nullable();
            // While Under review: which office holds the memo (assistant_chief_of_staff or chief_of_staff).
            $table->string('review_office', 40)->nullable();
            $table->boolean('is_late_notice')->default(false);
            $table->boolean('is_retrospective')->default(false);

            // CD-02 / CD-03: approval in the app or recorded from a memo.
            $table->string('approval_mode', 20)->nullable();
            $table->string('approval_reference', 100)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();

            // EX-01 / RP-01 / FR-04
            $table->dateTime('commenced_at')->nullable();
            $table->foreignId('commenced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->date('report_due_on')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            // FR-03 / OI-06
            $table->string('imprest_reference', 100)->nullable();
            $table->string('imprest_status', 20)->nullable();

            // Whether external participants count in cost per head.
            $table->boolean('count_externals_in_per_head')->default(true);

            // Magic-link provenance. A memo prepared through a Head of Department's delegated
            // link also records the officer who prepared it (created_by is that Head).
            $table->foreignId('submission_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prepared_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('submitted_by_name')->nullable();
            $table->string('submitted_by_email')->nullable();
            $table->string('submitted_by_phone', 30)->nullable();
            $table->dateTime('submitted_at')->nullable();

            // Stored totals, recomputed by App\Services\ActivityCosting on every participant/cost change.
            $table->unsignedInteger('staff_count')->default(0);
            $table->unsignedInteger('external_count')->default(0);
            $table->decimal('estimated_total', 15, 2)->default(0);
            // BR-10: planned total when approved, the baseline for the cost tolerance.
            $table->decimal('approved_estimated_total', 15, 2)->nullable();
            $table->decimal('actual_total', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->boolean('actuals_complete')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'start_date']);
            $table->index(['status', 'end_date']);
            $table->index(['status', 'review_office']);
            $table->index(['organising_department_id', 'start_date']);
            $table->index(['activity_category_id', 'activity_type_id']);
            $table->index('start_date');
            $table->index('report_due_on');
        });

        Schema::create('activity_tag', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'tag_id']);
        });

        // MD-04: an activity may have more than one location.
        Schema::create('activity_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('county_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('constituency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('venue')->nullable();
            $table->timestamps();

            $table->index(['county_id', 'activity_id']);
            $table->index(['constituency_id', 'activity_id']);
            $table->index(['region_id', 'activity_id']);
        });

        Schema::create('activity_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            // Null for an external participant.
            $table->foreignId('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->boolean('is_external')->default(false);
            $table->string('external_name')->nullable();
            $table->string('external_organisation')->nullable();
            $table->string('external_category', 30)->nullable();
            // Snapshot at the time of participation, so a later transfer does not rewrite history.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot for DSA costing, as the grade may change later.
            $table->string('job_grade', 20)->nullable();
            $table->string('role', 20)->default('participant');
            // Only some days of the activity; null means the whole activity.
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('nominated');
            $table->unsignedSmallInteger('days_planned')->nullable();
            $table->unsignedSmallInteger('days_attended')->nullable();
            $table->string('remarks', 500)->nullable();
            // PT-04: reason given to proceed despite an overlapping activity.
            $table->string('conflict_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'staff_id']);
            $table->index(['staff_id', 'status']);
            $table->index(['department_id', 'status']);
        });

        Schema::create('activity_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_category_id')->constrained()->restrictOnDelete();
            // Null = shared, activity-level cost (venue, materials).
            $table->foreignId('activity_participant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('unit_rate', 15, 2)->nullable();
            $table->decimal('estimated_amount', 15, 2)->default(0);
            $table->decimal('actual_amount', 15, 2)->nullable();
            // CB-01: computed DSA and its override.
            $table->boolean('is_computed')->default(false);
            $table->foreignId('dsa_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('computed_amount', 15, 2)->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->string('travel_mode', 10)->nullable();
            $table->string('finance_reference', 100)->nullable();
            // Who entered the actual amount: a Head of Department with the report, or the Office of the CEO from Finance returns.
            $table->foreignId('actual_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['activity_id', 'cost_category_id']);
        });

        Schema::create('activity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('original_name');
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submission_link_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['activity_id', 'kind']);
        });

        Schema::create('activity_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Set when the actor has no account (a magic-link submitter, or the system).
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['activity_id', 'id']);
        });

        // CD-02 / CD-03: CEO decisions, in the system or recorded on the CEO's behalf.
        Schema::create('ceo_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 20);
            $table->text('comment')->nullable();
            $table->string('mode', 30);
            $table->string('memo_reference', 100)->nullable();
            $table->date('memo_date')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('activity_documents')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['activity_id', 'id']);
        });

        // CD-04: directives tracked to completion.
        Schema::create('directives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->foreignId('responsible_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('completion_note')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('on_ceo_instruction')->default(false);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_on']);
        });

        // RP-02: the back-to-office report.
        Schema::create('report_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained()->cascadeOnDelete();
            // A Head of Department submits the report; the Office of the CEO accepts or returns it.
            $table->string('status', 20)->default('accepted');
            $table->date('received_on');
            $table->text('outputs_achieved');
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('activity_documents')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
        });

        // PT-07 / EX-04 / BR-10: changes after submission or approval.
        Schema::create('activity_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('summary', 500);
            $table->text('reason');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->boolean('returned_for_decision')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['activity_id', 'id']);
        });

        // Memo review comments and recommendations. Append-only: never edited or deleted.
        Schema::create('activity_review_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recommendation', 20)->nullable();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['activity_id', 'created_at']);
        });

        // Recall, release or reassignment of an officer part-way (App\Services\ParticipantReleases). The assignment is never deleted.
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

        Schema::table('submission_links', function (Blueprint $table) {
            $table->foreignId('activity_id')->nullable()->after('kind')->constrained()->nullOnDelete();
        });

        // The CEO's instruction to Finance (process the imprest) or Procurement (procure the tickets)
        // on an approved activity, acted on by that department's Head (App\Services\ActionInstructions).
        // One instruction of each kind per activity.
        Schema::create('action_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->restrictOnDelete();
            // App\Enums\InstructionKind: imprest (Finance) or tickets (Procurement).
            $table->string('kind', 20);
            // The department instructed, as configured when it was sent.
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            // App\Enums\InstructionStatus: sent, acknowledged, completed.
            $table->string('status', 20)->default('sent');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            // The imprest number, or the LPO / ticket numbers.
            $table->string('completion_reference', 100)->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'kind']);
            $table->index(['department_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_instructions');
        Schema::table('submission_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
        });
        Schema::dropIfExists('participant_releases');
        Schema::dropIfExists('activity_review_notes');
        Schema::dropIfExists('activity_amendments');
        Schema::dropIfExists('report_receipts');
        Schema::dropIfExists('directives');
        Schema::dropIfExists('ceo_decisions');
        Schema::dropIfExists('activity_status_histories');
        Schema::dropIfExists('activity_documents');
        Schema::dropIfExists('activity_costs');
        Schema::dropIfExists('activity_participants');
        Schema::dropIfExists('activity_locations');
        Schema::dropIfExists('activity_tag');
        Schema::dropIfExists('activities');
    }
};
