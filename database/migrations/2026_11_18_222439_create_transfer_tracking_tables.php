<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->string('destination_hash', 64)->nullable()->index();
            $table->string('destination_masked')->nullable();
        });
        Schema::create('transfer_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('call_leg_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('dentist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('retry_of_id')->nullable()->constrained('transfer_attempts')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('destination_masked')->nullable();
            $table->string('match_status')->default('unknown');
            $table->string('dial_status')->nullable();
            $table->string('status')->default('dialling')->index();
            $table->string('failure_reason')->nullable();
            $table->string('human_outcome')->default('unknown');
            $table->string('acceptance_confidence')->default('none');
            $table->string('fallback_status')->default('pending');
            $table->string('timing_quality')->default('received');
            foreach (['dial_started_at', 'ringing_at', 'answered_at', 'ended_at', 'transfer_completed_at', 'human_accepted_at'] as $field) {
                $table->timestamp($field, 6)->nullable();
            }
            foreach (['dial_to_answer_seconds', 'ringing_seconds', 'mobile_connected_seconds', 'private_handover_seconds', 'consultation_seconds'] as $field) {
                $table->decimal($field, 14, 6)->nullable();
            }
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['interaction_id', 'sequence']);
            $table->index('dial_started_at');
        });
        Schema::create('consultation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status')->default('unconfirmed');
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('ended_at', 6)->nullable();
            $table->decimal('consultation_seconds', 14, 6)->nullable();
            $table->json('intervals');
            $table->timestamps();
        });
        Schema::create('transfer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('call_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fingerprint', 64)->nullable()->unique();
            $table->string('kind');
            $table->string('outcome')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->timestamps();
            $table->index(['transfer_attempt_id', 'kind', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_events');
        Schema::dropIfExists('consultation_sessions');
        Schema::dropIfExists('transfer_attempts');
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->dropColumn(['destination_hash', 'destination_masked']);
        });
    }
};
