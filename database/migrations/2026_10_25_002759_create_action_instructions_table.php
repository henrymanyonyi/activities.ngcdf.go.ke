<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After approving a memo, the CEO instructs Finance (process the imprest) and
 * Procurement (buy the travel tickets). Each instruction goes to that
 * department's Head, who acknowledges it and records it done with a reference
 * (App\Services\ActionInstructions). One instruction of each kind per activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->restrictOnDelete();
            // App\Enums\InstructionKind: imprest (Finance) or tickets (Procurement).
            $table->string('kind', 20);
            // The department instructed, as configured when it was sent.
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            // App\Enums\InstructionStatus: sent, acknowledged, completed.
            $table->string('status', 20)->default('sent');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            // The imprest number, or the LPO / ticket numbers.
            $table->string('completion_reference', 100)->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'kind']);
            $table->index(['department_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_instructions');
    }
};
