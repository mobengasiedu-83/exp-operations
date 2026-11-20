<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('filters')->nullable();
            $table->unsignedSmallInteger('refresh_seconds')->default(3);
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category')->index();
            $table->string('metric');
            $table->unsignedInteger('threshold_seconds')->nullable();
            $table->string('severity')->default('warning')->index();
            $table->boolean('enabled')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pbx_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('interaction_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('fingerprint')->unique();
            $table->string('status')->default('open')->index();
            $table->string('severity')->default('warning')->index();
            $table->string('subject')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('dashboard_preferences');
    }
};
