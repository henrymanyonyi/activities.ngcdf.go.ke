<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the action behind a message was taken by the Office of the CEO (the CEO, the Chief of Staff or
 * the Assistant): its email is then headed, and sent, as from the Office of the CEO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->boolean('from_office')->default(false)->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropColumn('from_office');
        });
    }
};
