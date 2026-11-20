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
        Schema::create('structured_listener_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('pbx_connections')->cascadeOnDelete();
            $table->string('level')->default('info');
            $table->string('event_name')->nullable();
            $table->string('channel')->nullable();
            $table->unsignedInteger('lag_seconds')->nullable()->default(null);
            $table->unsignedInteger('backoff_seconds')->nullable()->default(null);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->useCurrent();
            $table->timestamps();

            $table->index(['connection_id', 'logged_at']);
            $table->index(['event_name', 'logged_at']);
            $table->index('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('structured_listener_logs');
    }
};
