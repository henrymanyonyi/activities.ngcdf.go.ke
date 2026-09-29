<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marks the placeholder DSA rates seeded at installation until Finance's circular replaces them (OI-01). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dsa_rates', function (Blueprint $table) {
            $table->boolean('is_provisional')->default(false)->after('effective_to');
        });
    }

    public function down(): void
    {
        Schema::table('dsa_rates', function (Blueprint $table) {
            $table->dropColumn('is_provisional');
        });
    }
};
