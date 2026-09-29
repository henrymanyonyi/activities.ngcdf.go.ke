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
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            // Employment window: participation comparisons only count the period someone was employed.
            $table->date('joined_on')->nullable();
            $table->date('exited_on')->nullable();
            // Future link to Smart NG-CDF's HqStaff record (join key: staff_number).
            $table->unsignedBigInteger('smart_hq_staff_id')->nullable()->index();
            $table->string('source', 20)->default('manual');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'department_id']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
