<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->nullable()->unique();
            $table->string('title');
            $table->text('purpose');
            $table->foreignId('activity_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('activity_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organising_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('target_audience')->nullable();
            $table->string('county', 60)->nullable();
            $table->string('location')->nullable();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->string('status_reason', 1000)->nullable();

            // Q3: approval in the app or by memo reference.
            $table->string('approval_mode', 20)->nullable();
            $table->string('approval_reference', 100)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();

            // Q4: whether external participants count in cost per head.
            $table->boolean('count_externals_in_per_head')->default(true);

            // Magic-link provenance.
            $table->foreignId('submission_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('submitted_by_name')->nullable();
            $table->string('submitted_by_email')->nullable();
            $table->string('submitted_by_phone', 30)->nullable();
            $table->dateTime('submitted_at')->nullable();

            // Stored totals, recomputed by App\Services\ActivityCosting on every participant/cost change.
            $table->unsignedInteger('staff_count')->default(0);
            $table->unsignedInteger('external_count')->default(0);
            $table->decimal('estimated_total', 15, 2)->default(0);
            $table->decimal('actual_total', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->boolean('actuals_complete')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'start_date']);
            $table->index(['organising_department_id', 'start_date']);
            $table->index(['activity_category_id', 'activity_type_id']);
            $table->index(['county', 'start_date']);
            $table->index('start_date');
        });

        Schema::create('activity_tag', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'tag_id']);
        });

        Schema::create('activity_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            // Null for an external participant.
            $table->foreignId('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->boolean('is_external')->default(false);
            $table->string('external_name')->nullable();
            $table->string('external_organisation')->nullable();
            $table->string('external_category', 30)->nullable();
            // Snapshot at the time of participation, so a later transfer does not rewrite history.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 20)->default('participant');
            $table->string('status', 20)->default('nominated');
            $table->unsignedSmallInteger('days_planned')->nullable();
            $table->unsignedSmallInteger('days_attended')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'staff_id']);
            $table->index(['staff_id', 'status']);
            $table->index(['department_id', 'status']);
        });

        Schema::create('activity_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_category_id')->constrained()->restrictOnDelete();
            // Null = shared, activity-level cost (venue, materials).
            $table->foreignId('activity_participant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('unit_rate', 15, 2)->nullable();
            $table->decimal('estimated_amount', 15, 2)->default(0);
            $table->decimal('actual_amount', 15, 2)->nullable();
            $table->string('travel_mode', 10)->nullable();
            $table->string('finance_reference', 100)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['activity_id', 'cost_category_id']);
        });

        Schema::create('activity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('original_name');
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submission_link_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['activity_id', 'kind']);
        });

        Schema::create('activity_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Set when the actor has no account (a magic-link submitter).
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['activity_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_status_histories');
        Schema::dropIfExists('activity_documents');
        Schema::dropIfExists('activity_costs');
        Schema::dropIfExists('activity_participants');
        Schema::dropIfExists('activity_tag');
        Schema::dropIfExists('activities');
    }
};
