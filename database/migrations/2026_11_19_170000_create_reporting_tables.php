<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category')->index();
            $table->string('grain')->default('daily')->index();
            $table->text('description');
            $table->string('permission')->default('view reports');
            $table->boolean('sensitive')->default(false);
            $table->boolean('enabled')->default(true)->index();
            $table->json('kpis');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_definition_id')->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->date('period_start')->index();
            $table->date('period_end');
            $table->string('source')->default('manual')->index();
            $table->string('status')->default('completed')->index();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->index();
            $table->unsignedInteger('kpi_count')->default(0);
            $table->unsignedInteger('breakdown_count')->default(0);
            $table->json('totals');
            $table->json('governance')->nullable();
            $table->string('checksum', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['report_definition_id', 'period_start', 'period_end', 'source'], 'report_runs_period_source_unique');
        });

        Schema::create('kpi_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_run_id')->constrained()->cascadeOnDelete();
            $table->string('key')->index();
            $table->string('category')->index();
            $table->string('unit');
            $table->decimal('value', 16, 4)->nullable();
            $table->decimal('numerator', 16, 4)->nullable();
            $table->decimal('denominator', 16, 4)->nullable();
            $table->json('exclusions')->nullable();
            $table->text('definition');
            $table->timestamp('computed_at');
            $table->timestamps();
            $table->unique(['report_run_id', 'key']);
        });

        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_run_id')->constrained()->restrictOnDelete();
            $table->string('format')->default('csv');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('checksum', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['exports', 'kpi_snapshots', 'report_runs', 'report_definitions'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
