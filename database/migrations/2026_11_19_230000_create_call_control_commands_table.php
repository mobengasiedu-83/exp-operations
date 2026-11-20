<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_control_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('interaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dentist_number_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('active_interaction_id')->nullable()->unique();
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->string('action', 32);
            $table->string('expected_version', 128);
            $table->string('source_state', 40)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('error_code', 40)->nullable();
            $table->string('destination_masked', 64)->nullable();
            $table->text('destination')->nullable();
            $table->string('dentist_uniqueid')->nullable();
            $table->boolean('human_accepted')->default(false);
            $table->timestamp('acceptance_recorded_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['interaction_id', 'status']);
        });
        $permission = Permission::findOrCreate('control calls', 'web');
        foreach (Role::whereIn('name', ['Agent', 'Supervisor', 'Administrator', 'Super Administrator'])->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('call_control_commands');
    }
};
