<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Human label for a role, as in Smart NG-CDF ("CEO" rather than the slug "ceo"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles', 'roles'), function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles', 'roles'), function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
