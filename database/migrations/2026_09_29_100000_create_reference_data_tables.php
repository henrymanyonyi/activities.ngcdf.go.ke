<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup lists and configuration maintained in Settings: departments,
 * designations, regions and offices (created on the fly by
 * App\Services\ParticipantListImporter and tidied in Settings), geography
 * (MD-04), DSA rates (MD-05), budget lines (MD-08) and thresholds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->nullable();
            // Future link to Smart NG-CDF's Cluster ("region") model.
            $table->unsignedBigInteger('smart_cluster_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_headquarters')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('job_group', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('activity_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['activity_category_id', 'name']);
        });

        Schema::create('programmes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cost_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 30)->unique();
            $table->string('default_scope', 20)->default('participant');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamps();
        });

        // MD-04: region (10), county (47), constituency (290). Snapshot of Smart NG-CDF's geography.
        Schema::create('counties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name')->unique();
            // Retired 22 Oct 2026 (one DSA rate per grade, wherever the activity is); kept for history, never read.
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

        // MD-05: DSA rates by job grade, with effective dates. One standard rate per grade whatever the
        // destination (22 Oct 2026): standard rates have no destination_category; rows that have one are
        // the retired Cities / Other towns rates, kept for history and never read (DsaRate::lookup).
        Schema::create('dsa_rates', function (Blueprint $table) {
            $table->id();
            $table->string('job_grade', 20);
            $table->string('destination_category', 40)->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // The retired placeholder rates seeded before the standard rates were approved (OI-01).
            $table->boolean('is_provisional')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_grade', 'destination_category', 'effective_from']);
        });

        // Department budgets per financial year, changed only through App\Services\Budgeting:
        // prepared, submitted to the CEO, approved or returned with a reason.
        Schema::create('department_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('financial_year', 9);
            $table->string('status', 20)->default('draft');
            // The department's workplan: what it sets out to achieve in the year. Its activities are the lines.
            $table->text('objectives')->nullable();
            $table->text('notes')->nullable();
            // The workplan document or spreadsheet the department uploaded, encrypted (App\Services\DocumentStore).
            $table->string('workplan_file_name')->nullable();
            $table->string('workplan_file_path')->nullable();
            $table->string('workplan_file_mime', 120)->nullable();
            $table->unsignedBigInteger('workplan_file_size')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'financial_year']);
            $table->index(['financial_year', 'status']);
        });

        // MD-08: budget lines (votes) of a department budget.
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_budget_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('financial_year', 9);
            $table->string('code', 40)->nullable();
            // The workplan activity or vote.
            $table->string('name');
            $table->text('objective')->nullable();
            $table->text('deliverables')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            // The quarters of the financial year it runs in, e.g. "Q1,Q3".
            $table->string('quarters', 20)->nullable();
            $table->string('responsible_officer')->nullable();
            // What the department asked for when it last submitted; `amount` is the allocation
            // (the CEO may change it before approving, and it is fixed once approved).
            $table->decimal('proposed_amount', 15, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'financial_year']);
        });

        // The review history of a department's workplan and budget: each submission and resubmission
        // (with the lines as submitted), the CEO's comments and changes to amounts before approval,
        // returns and the approval (with the lines as approved). Written once, never edited.
        Schema::create('budget_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained()->nullOnDelete();
            // submitted, resubmitted, comment, amount_changed, returned, approved
            $table->string('event', 30);
            $table->text('comment')->nullable();
            $table->decimal('amount_before', 15, 2)->nullable();
            $table->decimal('amount_after', 15, 2)->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['department_budget_id', 'id']);
        });

        // An approved budget changes only by adjustments, with the same approval.
        Schema::create('budget_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_budget_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            // Increase or reduce this line; in a reallocation, the line that receives the money.
            $table->foreignId('budget_line_id')->nullable()->constrained()->restrictOnDelete();
            // In a reallocation, the line the money comes from.
            $table->foreignId('from_budget_line_id')->nullable()->constrained('budget_lines')->restrictOnDelete();
            // A supplementary allocation under a vote the budget does not have yet; the line is created on approval.
            $table->string('new_line_code', 40)->nullable();
            $table->string('new_line_name')->nullable();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            // From when the change applies, and the line allocations it changed, recorded when it is approved.
            $table->date('effective_on')->nullable();
            $table->decimal('line_before', 15, 2)->nullable();
            $table->decimal('line_after', 15, 2)->nullable();
            $table->decimal('from_line_before', 15, 2)->nullable();
            $table->decimal('from_line_after', 15, 2)->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_budget_id', 'status']);
        });

        // Configurable thresholds and tolerances (OI-04, OI-05, OI-07, BR-10), editable in Settings.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('value', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A Head of Department's department (the column is created with users).
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('budget_adjustments');
        Schema::dropIfExists('budget_reviews');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('department_budgets');
        Schema::dropIfExists('dsa_rates');
        Schema::dropIfExists('constituencies');
        Schema::dropIfExists('counties');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('cost_categories');
        Schema::dropIfExists('programmes');
        Schema::dropIfExists('activity_types');
        Schema::dropIfExists('activity_categories');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('offices');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('departments');
    }
};
