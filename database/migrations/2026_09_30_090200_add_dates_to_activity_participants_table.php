<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A participant may take part on only some days of an activity. Null means
 * the whole activity; days_planned is kept equal to the span of these dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_participants', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('role');
            $table->date('end_date')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('activity_participants', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date']);
        });
    }
};
