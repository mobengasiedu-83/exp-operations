<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('callbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('consent_record_id')->constrained('consent_records')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->string('priority')->default('routine');
            $table->text('reason');
            $table->timestamp('due_at')->index();
            $table->time('window_start');
            $table->time('window_end');
            $table->timestamp('escalated_at')->nullable();
            $table->string('outcome')->nullable();
            $table->text('outcome_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['assigned_to', 'status', 'due_at']);
        });
        Schema::create('callback_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('callback_id')->constrained()->restrictOnDelete();
            $table->foreignId('attempted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome');
            $table->boolean('neutral_voicemail_confirmed')->default(false);
            $table->text('note')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
            $table->index(['callback_id', 'attempted_at']);
        });
        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dentist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('route');
            $table->string('destination_name');
            $table->text('destination_contact')->nullable();
            $table->text('reason');
            $table->string('status')->default('pending')->index();
            $table->string('action_reference')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->string('outcome')->nullable();
            $table->text('outcome_note')->nullable();
            $table->timestamp('outcome_recorded_at')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['case_id', 'route', 'status']);
        });
        Schema::create('case_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('next_action');
            $table->timestamp('due_at');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason')->nullable();
            $table->timestamps();
            $table->index(['assigned_to', 'ended_at']);
            $table->index(['case_id', 'ended_at']);
        });
        Schema::create('shift_handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outgoing_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('incoming_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shift_started_at');
            $table->timestamp('shift_ended_at');
            $table->json('unresolved_cases');
            $table->text('notes')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['outgoing_user_id', 'shift_ended_at']);
        });
    }

    public function down(): void
    {
        foreach (['shift_handovers', 'case_assignments', 'referrals', 'callback_attempts', 'callbacks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
