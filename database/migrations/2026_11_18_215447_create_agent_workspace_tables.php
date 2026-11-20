<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_protocols', function (Blueprint $table): void {
            $table->id();
            $table->string('version')->unique();
            $table->string('title');
            $table->string('status')->default('draft')->index();
            $table->text('privacy_notice');
            $table->boolean('allow_guardian')->default(false);
            $table->json('guidance');
            $table->text('faq_answer');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            foreach (['clinical', 'privacy'] as $approval) {
                $table->foreignId($approval.'_approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp($approval.'_approved_at')->nullable();
                $table->string($approval.'_approval_reference')->nullable();
            }
            $table->timestamps();
        });
        Schema::create('screening_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('screening_protocol_id')->constrained()->restrictOnDelete();
            $table->text('prompt');
            $table->string('positive_priority');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->unique(['screening_protocol_id', 'position']);
        });
        Schema::create('cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('association_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('association_verified_at');
            $table->string('status')->default('open')->index();
            $table->string('priority')->nullable()->index();
            $table->string('contact_kind')->default('genuine');
            $table->string('concern_type')->default('clinical');
            $table->string('age_band')->default('unknown');
            foreach (['preferred_name', 'callback_number', 'guardian_name', 'guardian_relationship', 'concern', 'onset', 'impact', 'next_step'] as $field) {
                $table->text($field)->nullable();
            }
            $table->boolean('guardian_confirmed')->default(false);
            $table->string('disposition')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['assigned_to', 'status']);
        });
        Schema::create('screenings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('screening_protocol_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority');
            $table->boolean('complete');
            $table->timestamp('completed_at');
            $table->timestamps();
        });
        Schema::create('screening_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('screening_id')->constrained()->restrictOnDelete();
            $table->foreignId('screening_question_id')->constrained()->restrictOnDelete();
            $table->string('answer');
            $table->timestamps();
            $table->unique(['screening_id', 'screening_question_id']);
        });
        Schema::create('consent_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('screening_protocol_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->boolean('callback_allowed')->default(false);
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
        Schema::create('case_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['case_notes', 'consent_records', 'screening_answers', 'screenings', 'cases', 'screening_questions', 'screening_protocols'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
