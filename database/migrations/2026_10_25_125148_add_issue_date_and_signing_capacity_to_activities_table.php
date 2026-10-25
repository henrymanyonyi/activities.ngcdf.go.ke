<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Head of Department's memo carries the date it is issued (printed on the
 * memo as DATE) and is signed with the Head's designation, prefixed "Ag."
 * when they sign in an acting capacity. source_received_on keeps its meaning
 * (the day the Office of the CEO received a memo it records itself); older
 * memos have no issue date and print the date they were submitted, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->date('issued_on')->nullable()->after('source_received_on');
            // The Head's designation as signed on the memo, e.g. "Manager, ICT", and whether they sign as acting.
            $table->string('hod_designation')->nullable()->after('responsible_hod_name');
            $table->boolean('hod_acting')->default(false)->after('hod_designation');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['issued_on', 'hod_designation', 'hod_acting']);
        });
    }
};
