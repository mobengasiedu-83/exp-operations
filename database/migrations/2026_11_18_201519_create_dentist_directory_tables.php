<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dentists', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('dentist_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dentist_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->unique();
            $table->string('original', 40);
            $table->string('label')->default('Mobile');
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('approved')->index();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['dentist_id', 'status']);
        });

        Schema::create('dentist_rosters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dentist_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('shift')->index();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dentist_id', 'starts_at']);
        });

        Schema::create('dentist_availability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dentist_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dentist_id', 'starts_at']);
        });

        Schema::create('dentist_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dentist_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('number');
            $table->string('issuer')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['dentist_id', 'type', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['dentist_credentials', 'dentist_availability', 'dentist_rosters', 'dentist_numbers', 'dentists'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
