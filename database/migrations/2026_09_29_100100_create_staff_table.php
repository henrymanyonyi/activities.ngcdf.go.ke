<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            // Unique where present; some staff (e.g. new or seconded) arrive without one.
            $table->string('staff_number', 30)->nullable()->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            // MD-01: job grade drives the DSA rate.
            $table->string('job_grade', 20)->nullable();
            // Permanent, casual or attachee (App\Enums\EmploymentType). Only permanent staff must have a PF number.
            $table->string('employment_type', 20)->default('permanent');
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            // Employment window: participation comparisons only count the period someone was employed.
            $table->date('joined_on')->nullable();
            $table->date('exited_on')->nullable();
            $table->date('contract_ends_on')->nullable();
            // Future link to Smart NG-CDF's HqStaff record (join key: staff_number).
            $table->unsignedBigInteger('smart_hq_staff_id')->nullable()->index();
            $table->string('source', 20)->default('manual');
            // Who added the officer by hand, so a Head of Department can correct the records they added (and only those).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'department_id']);
            $table->index('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('staff_id')->references('id')->on('staff')->nullOnDelete();
        });

        // A promotion, transfer or change of system access (App\Services\StaffChanges).
        // Written once and never edited: who held which position and access, and from when.
        Schema::create('staff_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('from_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('to_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            // System access before and after: a role name, or null for none.
            $table->string('from_role', 40)->nullable();
            $table->string('to_role', 40)->nullable();
            $table->date('effective_on');
            $table->text('reason');
            $table->string('reference', 100)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['staff_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_changes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
        });
        Schema::dropIfExists('staff');
    }
};
