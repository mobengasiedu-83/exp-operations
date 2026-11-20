<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reviewed_at')->index();
            $table->string('status')->default('draft')->index();
            $table->unsignedSmallInteger('total')->nullable();
            $table->string('outcome')->nullable()->index();
            $table->boolean('critical_fail')->default(false);
            $table->text('critical_note')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('coaching_required')->default(false);
            $table->timestamp('coaching_due_at')->nullable();
            $table->timestamp('coaching_completed_at')->nullable();
            $table->text('coaching_note')->nullable();
            $table->foreignId('coached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalised_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['agent_id', 'reviewed_at']);
            $table->index(['outcome', 'status']);
        });

        Schema::create('qa_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qa_review_id')->constrained()->restrictOnDelete();
            $table->string('criterion');
            $table->unsignedSmallInteger('weight');
            $table->unsignedTinyInteger('rating');
            $table->unsignedSmallInteger('points');
            $table->boolean('critical')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['qa_review_id', 'criterion']);
        });

        Schema::create('qa_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qa_review_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('secondary_total');
            $table->unsignedSmallInteger('variance');
            $table->unsignedSmallInteger('agreed_total')->nullable();
            $table->string('status')->default('open')->index();
            $table->text('note')->nullable();
            $table->foreignId('agreed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('agreed_at')->nullable();
            $table->timestamps();
            $table->unique(['qa_review_id', 'reviewer_id']);
        });

        Schema::create('training_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('day')->index();
            $table->string('title');
            $table->text('objectives');
            $table->longText('content');
            $table->boolean('requires_role_play')->default(true);
            $table->unsignedTinyInteger('pass_mark')->default(80);
            $table->string('status')->default('draft')->index();
            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['day', 'status']);
        });

        Schema::create('training_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_module_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind')->index();
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('pass_mark');
            $table->string('result')->index();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'kind', 'result']);
        });

        Schema::create('certifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('active')->index();
            $table->date('issued_at');
            $table->date('expires_at')->index();
            $table->foreignId('authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference');
            $table->json('curriculum_snapshot');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('category')->index();
            $table->string('severity')->index();
            $table->string('status')->default('open')->index();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('qa_review_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->string('summary');
            $table->text('description');
            $table->text('immediate_action')->nullable();
            $table->text('escalation_note')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('escalation_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['category', 'status']);
            $table->index(['severity', 'status']);
        });

        Schema::create('corrective_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->timestamp('due_at')->index();
            $table->string('status')->default('open')->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_evidence_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose');
            $table->timestamp('accessed_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'incident_evidence_accesses', 'corrective_actions', 'incidents', 'certifications',
            'training_assessments', 'training_modules', 'qa_calibrations', 'qa_scores', 'qa_reviews',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
