<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account made from an officer on the staff list keeps the link, and its
 * mobile number receives the action-needed SMS (App\Services\OutboundAlerts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->after('department_id')->constrained('staff')->nullOnDelete();
            $table->string('phone', 20)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_id');
            $table->dropColumn('phone');
        });
    }
};
