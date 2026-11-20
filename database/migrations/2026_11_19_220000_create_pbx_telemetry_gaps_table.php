<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pbx_telemetry_gaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pbx_connection_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at', 6);
            $table->timestamp('ended_at', 6)->nullable();
            $table->string('reason', 40);
            $table->timestamps();
            $table->index(['pbx_connection_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbx_telemetry_gaps');
    }
};
