<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the schema in line with the FAPM Functional Requirements Document
 * (NGCDF_FAPM_FRD_v3, v1.2). Additive, except the three single-location
 * columns on activities, which were never used (replaced by activity_locations).
 */
return new class extends Migration
{
    public function up(): void
    {
        // MD-04: region (10), county (47), constituency (290). Snapshot of Smart NG-CDF's geography.
        Schema::create('counties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name')->unique();
            // MD-05 / OI-01: DSA destination category the county falls in.
            $table->string('dsa_destination_category', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('constituencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->foreignId('county_id')->constrained()->restrictOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['county_id', 'name']);
        });

        Schema::table('staff', function (Blueprint $table) {
            // MD-01: job grade drives the DSA rate.
            $table->string('job_grade', 20)->nullable()->after('designation_id');
        });

        // MD-05: DSA rates by job grade and destination category, with effective dates.
        Schema::create('dsa_rates', function (Blueprint $table) {
            $table->id();
            $table->string('job_grade', 20);
            $table->string('destination_category', 40);
            $table->decimal('amount', 15, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_grade', 'destination_category', 'effective_from']);
        });

        // MD-08: budget lines per department per financial year.
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('financial_year', 9);
            $table->string('code', 40)->nullable();
            $table->string('name');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['department_id', 'financial_year']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['county', 'start_date']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
            $table->dropColumn(['county', 'location']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedSmallInteger('days')->default(1)->after('end_date');
            $table->unsignedSmallInteger('nights')->default(0)->after('days');
            $table->text('expected_outputs')->nullable()->after('purpose');
            $table->text('notes')->nullable()->after('expected_outputs');
            $table->string('source_reference', 100)->nullable()->after('target_audience');
            $table->date('source_received_on')->nullable()->after('source_reference');
            $table->foreignId('budget_line_id')->nullable()->after('programme_id')->constrained()->nullOnDelete();

            $table->boolean('is_late_notice')->default(false)->after('status_reason');
            $table->boolean('is_retrospective')->default(false)->after('is_late_notice');

            // EX-01 / RP-01 / FR-04
            $table->dateTime('commenced_at')->nullable()->after('approved_at');
            $table->foreignId('commenced_by')->nullable()->after('commenced_at')->constrained('users')->nullOnDelete();
            $table->date('actual_start_date')->nullable()->after('commenced_by');
            $table->date('actual_end_date')->nullable()->after('actual_start_date');
            $table->dateTime('completed_at')->nullable()->after('actual_end_date');
            $table->date('report_due_on')->nullable()->after('completed_at');
            $table->dateTime('closed_at')->nullable()->after('report_due_on');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();

            // FR-03 / OI-06
            $table->string('imprest_reference', 100)->nullable()->after('closed_by');
            $table->string('imprest_status', 20)->nullable()->after('imprest_reference');

            $table->index(['status', 'end_date']);
            $table->index('report_due_on');
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

        Schema::table('activity_participants', function (Blueprint $table) {
            // Snapshot for DSA costing, as the grade may change later.
            $table->string('job_grade', 20)->nullable()->after('office_id');
            // PT-04: reason given to proceed despite an overlapping activity.
            $table->string('conflict_reason', 500)->nullable()->after('remarks');
        });

        Schema::table('activity_costs', function (Blueprint $table) {
            // CB-01: computed DSA and its override.
            $table->boolean('is_computed')->default(false)->after('actual_amount');
            $table->foreignId('dsa_rate_id')->nullable()->after('is_computed')->constrained()->nullOnDelete();
            $table->decimal('computed_amount', 15, 2)->nullable()->after('dsa_rate_id');
            $table->string('override_reason', 500)->nullable()->after('computed_amount');
        });

        // 7.5 CEO decisions, in the system or recorded on the CEO's behalf.
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

        // 7.7 back-to-office report.
        Schema::create('report_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('received_on');
            $table->text('outputs_achieved');
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('activity_documents')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // PT-07 / EX-04 / BR-10: changes after approval.
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

        // CF-06: sign-ins, views, exports and prints (changes are in audit_logs).
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('event', 30);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        // Configurable thresholds and tolerances (OI-04, OI-05, OI-07, BR-10), editable by the Chief of Staff.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('value', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // CF-04: lockout after repeated failed sign-ins.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('is_active');
            $table->dateTime('locked_until')->nullable()->after('failed_login_attempts');
            $table->dateTime('last_login_at')->nullable()->after('locked_until');
        });

        // NT-01: in-app alerts.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_until', 'last_login_at']);
        });

        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('activity_amendments');
        Schema::dropIfExists('report_receipts');
        Schema::dropIfExists('directives');
        Schema::dropIfExists('ceo_decisions');

        Schema::table('activity_costs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dsa_rate_id');
            $table->dropColumn(['is_computed', 'computed_amount', 'override_reason']);
        });

        Schema::table('activity_participants', function (Blueprint $table) {
            $table->dropColumn(['job_grade', 'conflict_reason']);
        });

        Schema::dropIfExists('activity_locations');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['status', 'end_date']);
            $table->dropIndex(['report_due_on']);
            $table->dropConstrainedForeignId('budget_line_id');
            $table->dropConstrainedForeignId('commenced_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn([
                'days', 'nights', 'expected_outputs', 'notes', 'source_reference', 'source_received_on',
                'is_late_notice', 'is_retrospective', 'commenced_at', 'actual_start_date', 'actual_end_date',
                'completed_at', 'report_due_on', 'closed_at', 'imprest_reference', 'imprest_status',
            ]);
            $table->string('county', 60)->nullable();
            $table->string('location')->nullable();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('dsa_rates');

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('job_grade');
        });

        Schema::dropIfExists('constituencies');
        Schema::dropIfExists('counties');
    }
};
