<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promotion, demotion, transfer or change of access recorded now to take effect on a later
 * date (27 October 2026). App\Services\StaffChanges applies it on its date (the
 * activities:apply-staff-changes command) and links the staff_changes record it wrote.
 * Cancelled or failed changes are kept, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_staff_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->date('effective_on');
            // What StaffChanges::apply() receives: department_id, designation_id, access, replace_head, reason, reference.
            $table->json('payload');
            $table->string('status', 20)->default('scheduled');
            $table->text('outcome')->nullable();
            $table->foreignId('staff_change_id')->nullable()->constrained('staff_changes')->nullOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_staff_changes');
    }
};
