<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Head of Department submits the activity report with what was actually
 * spent on each cost line; the Office of the CEO accepts it or returns it for
 * correction (business owner, 21 October 2026). Additive: reports recorded
 * before this are accepted ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_receipts', function (Blueprint $table) {
            $table->string('status', 20)->default('accepted')->after('activity_id');
            $table->foreignId('submitted_by')->nullable()->after('recorded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('reviewed_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('return_reason')->nullable()->after('reviewed_at');
        });

        Schema::table('activity_costs', function (Blueprint $table) {
            // Who entered the actual amount: a Head of Department with the report, or the Office of the CEO from Finance returns.
            $table->foreignId('actual_recorded_by')->nullable()->after('finance_reference')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_costs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actual_recorded_by');
        });
        Schema::table('report_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'submitted_at', 'reviewed_at', 'return_reason']);
        });
    }
};
