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
        Schema::create('ami_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_connection_id')->constrained()->cascadeOnDelete();
            $table->string('event_name')->index();
            $table->string('uniqueid')->nullable()->index();
            $table->string('linkedid')->nullable()->index();
            $table->string('channel')->nullable();
            $table->json('payload');
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ami_events');
    }
};
