<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('connection_health', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('pbx_connections')->cascadeOnDelete();
            $table->string('status')->default('disconnected');
            $table->unsignedTinyInteger('reconnect_attempts')->default(0);
            $table->unsignedInteger('backoff_seconds')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->unsignedTinyInteger('lag_seconds')->nullable()->default(null);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connection_health');
    }
};
