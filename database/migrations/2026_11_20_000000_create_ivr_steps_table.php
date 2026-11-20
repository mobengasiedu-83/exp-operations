<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ivr_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pbx_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('uniqueid')->nullable()->index();
            $table->string('menu')->nullable();
            $table->string('option', 8)->nullable()->index();
            $table->string('application')->nullable();
            $table->string('app_data', 255)->nullable();
            $table->timestamp('occurred_at', 6)->index();
            $table->string('timing_quality')->default('received');
            $table->string('fingerprint', 64)->unique();
            $table->timestamps();
            $table->index(['interaction_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ivr_steps');
    }
};
