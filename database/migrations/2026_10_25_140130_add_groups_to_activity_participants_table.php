<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group of officers added by number without their names, e.g. "Fund Account
 * Managers (FAMs)" × 10 (26 October 2026): a participant with no staff record,
 * a label, a headcount and a DSA scale. Every other participant counts as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_participants', function (Blueprint $table) {
            $table->string('group_label')->nullable()->after('external_category');
            $table->unsignedSmallInteger('headcount')->default(1)->after('group_label');
        });
    }

    public function down(): void
    {
        Schema::table('activity_participants', function (Blueprint $table) {
            $table->dropColumn(['group_label', 'headcount']);
        });
    }
};
