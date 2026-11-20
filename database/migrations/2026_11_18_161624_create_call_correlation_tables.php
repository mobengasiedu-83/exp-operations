<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ami_events', function (Blueprint $table): void {
            $table->json('correlation_data')->nullable();
        });
        foreach (['cdr_records', 'cel_records'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('pbx_connection_id')->nullable()->constrained()->nullOnDelete();
                $table->index(['pbx_connection_id', 'uniqueid']);
            });
        }
        Schema::create('interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pbx_connection_id')->constrained()->cascadeOnDelete();
            $table->string('correlation_key');
            $table->json('correlation_aliases')->nullable();
            $table->boolean('is_exp')->default(false);
            $table->string('status')->default('active')->index();
            $table->string('timing_quality')->default('received');
            $table->string('reconciliation_status')->default('pending')->index();
            $table->timestamp('started_at', 6)->nullable()->index();
            $table->timestamp('ended_at', 6)->nullable();
            $table->timestamp('reconciled_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['pbx_connection_id', 'correlation_key']);
        });
        Schema::create('call_legs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pbx_connection_id')->constrained()->cascadeOnDelete();
            $table->string('uniqueid');
            $table->string('channel')->nullable();
            $table->string('role')->default('unknown');
            $table->string('entry_kind')->nullable();
            $table->string('dial_status')->nullable();
            $table->boolean('observed_creation')->default(false);
            foreach (['started_at', 'dial_started_at', 'ringing_at', 'answered_at', 'ended_at'] as $time) {
                $table->timestamp($time, 6)->nullable();
            }
            $table->timestamps();
            $table->unique(['pbx_connection_id', 'uniqueid']);
        });
        Schema::create('call_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pbx_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interaction_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ami_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint', 64)->unique();
            $table->string('event_name');
            $table->timestamp('occurred_at', 6);
            $table->string('timing_quality');
            $table->json('data');
            $table->timestamps();
            $table->index(['interaction_id', 'occurred_at', 'id']);
            $table->index('ami_event_id');
        });
        Schema::create('call_bridges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interaction_id')->constrained()->cascadeOnDelete();
            $table->string('bridge_uniqueid');
            $table->string('stage');
            $table->json('participants');
            $table->timestamp('started_at', 6);
            $table->timestamp('ended_at', 6)->nullable();
            $table->decimal('duration_seconds', 14, 6)->nullable();
            $table->timestamps();
        });
        Schema::create('call_timing_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_leg_id')->unique()->constrained()->cascadeOnDelete();
            foreach (['outbound_setup_seconds', 'dial_to_answer_seconds', 'ringing_seconds', 'mobile_connected_seconds', 'private_handover_seconds', 'consultation_seconds'] as $metric) {
                $table->decimal($metric, 14, 6)->nullable();
            }
            $table->timestamp('human_accepted_at', 6)->nullable();
            $table->string('acceptance_status')->default('unconfirmed');
            $table->timestamps();
        });
        Schema::create('correlation_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pbx_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interaction_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('call_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint', 64)->unique();
            $table->string('category')->index();
            $table->string('code')->index();
            $table->string('subject')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['correlation_exceptions', 'call_timing_metrics', 'call_bridges', 'call_events', 'call_legs', 'interactions'] as $name) {
            Schema::dropIfExists($name);
        }
        foreach (['cdr_records', 'cel_records'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['pbx_connection_id', 'uniqueid']);
                $table->dropConstrainedForeignId('pbx_connection_id');
            });
        }
        Schema::table('ami_events', fn (Blueprint $table) => $table->dropColumn('correlation_data'));
    }
};
