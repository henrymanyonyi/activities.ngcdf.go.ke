<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Magic links a memo originator uses to submit an activity without an account.
 * The token is looked up by its SHA-256 hash; an encrypted copy is kept only so
 * CEO/Admin can copy the link again (App\Services\SubmissionLinkService).
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
            $table->timestamps();

            $table->index(['revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_links');
    }
};
