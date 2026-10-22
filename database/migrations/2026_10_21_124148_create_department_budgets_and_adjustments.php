<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgeting (business owner, 21 October 2026): a department's budget for a
 * financial year is prepared as a draft by the CEO, the Chief of Staff or the
 * Assistant, and counts only once the CEO approves it. Adjustments during the
 * year follow the same approval. Additive: the budget lines entered before
 * this change are grouped into one budget per department and year, recorded
 * as approved, so the figures already shown do not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('financial_year', 9);
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
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

        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignId('department_budget_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->softDeletes();
        });

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

        $now = now();
        DB::table('budget_lines')->whereNull('department_budget_id')
            ->select('department_id', 'financial_year')->distinct()->get()
            ->each(function (object $group) use ($now): void {
                $budgetId = DB::table('department_budgets')->insertGetId([
                    'department_id' => $group->department_id,
                    'financial_year' => $group->financial_year,
                    'status' => 'approved',
                    'notes' => 'Budget lines entered in Reference data before budget approval was introduced (21 October 2026).',
                    'approved_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('budget_lines')
                    ->where('department_id', $group->department_id)
                    ->where('financial_year', $group->financial_year)
                    ->whereNull('department_budget_id')
                    ->update(['department_budget_id' => $budgetId]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_adjustments');
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_budget_id');
            $table->dropSoftDeletes();
        });
        Schema::dropIfExists('department_budgets');
    }
};
