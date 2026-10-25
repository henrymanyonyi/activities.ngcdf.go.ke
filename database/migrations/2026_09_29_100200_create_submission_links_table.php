<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Magic links. Two kinds: `intake`, a link the Office of the CEO gives a memo
 * originator to submit activities without an account (behind
 * ACTIVITIES_SUBMISSION_LINKS); and `delegated`, a link a Head of Department
 * gives one officer on the staff list to prepare one memo for the Head's
 * review (App\Services\DelegatedMemos), opened only after a one-time code
 * sent to that officer's email or mobile. The token is looked up by its
 * SHA-256 hash; an encrypted copy is kept only so the link can be copied
 * again (App\Services\SubmissionLinkService). The memo a delegated link
 * prepares (`activity_id`) is added with the activities table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_links', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->string('label');
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone', 30)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('activity_category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('instructions')->nullable();
            $table->dateTime('expires_at');
            $table->unsignedSmallInteger('max_submissions')->nullable();
            $table->unsignedSmallInteger('submissions_count')->default(0);
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20)->default('intake');
            // Delegated: the officer asked to prepare the memo.
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            // Delegated: the one-time code that proves the officer holds the email or mobile on the staff list.
            $table->string('otp_hash', 64)->nullable();
            $table->dateTime('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->dateTime('verified_at')->nullable();
            // Delegated: the memo was submitted to the Head of Department, so the link no longer opens it for editing.
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['revoked_at', 'expires_at']);
            $table->index(['kind', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_links');
    }
};
