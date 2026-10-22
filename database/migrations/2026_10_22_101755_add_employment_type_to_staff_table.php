<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR register (October 2026) covers permanent staff, casuals and
 * attachees. Casuals and attachees have no PF number, so the type also
 * decides whether the PF number is required. Existing records are permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('employment_type', 20)->default('permanent')->after('job_grade');
            $table->date('contract_ends_on')->nullable()->after('exited_on');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['employment_type', 'contract_ends_on']);
        });
    }
};
