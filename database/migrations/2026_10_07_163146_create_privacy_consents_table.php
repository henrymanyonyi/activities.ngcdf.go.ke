<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent to the NG-CDF Board Data Privacy Statement, one row per person per
 * statement version (same design as Smart NG-CDF). A signed-in account's
 * consent is keyed by its id; a visitor's (no account) by the SHA-256 hash
 * of a random token held in an encrypted cookie, so the raw token never sits
 * in the database and nothing else about the visitor is stored. See
 * PrivacyConsentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_consents', function (Blueprint $table) {
            $table->id();
            $table->string('consent_type', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->char('consent_token_hash', 64)->nullable()->unique();
            $table->string('privacy_statement_version', 20);
            $table->timestamp('consented_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            // One consent per account per version, so a double click or a
            // second tab cannot record it twice.
            $table->unique(['user_id', 'privacy_statement_version']);
            $table->index(['privacy_statement_version', 'consent_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_consents');
    }
};
