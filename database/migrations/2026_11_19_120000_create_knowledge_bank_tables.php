<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('category')->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('draft')->index();
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_due_at')->nullable();
            $table->timestamps();
            $table->index(['category', 'status']);
        });
        Schema::create('script_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('language', 10)->default('en');
            $table->string('title');
            $table->longText('body');
            $table->text('change_summary')->nullable();
            $table->string('status')->default('draft')->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['knowledge_article_id', 'version', 'language']);
        });
        Schema::create('approval_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->restrictOnDelete();
            $table->foreignId('script_version_id')->constrained()->restrictOnDelete();
            $table->string('area');
            $table->string('decision')->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_name')->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['script_version_id', 'area']);
            $table->index(['decision', 'area']);
        });
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->restrictOnDelete();
            $table->foreignId('script_version_id')->constrained()->restrictOnDelete();
            $table->string('language', 10);
            $table->string('title');
            $table->longText('body');
            $table->string('status')->default('draft')->index();
            $table->date('effective_from')->nullable();
            $table->foreignId('translated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['script_version_id', 'language']);
        });
    }

    public function down(): void
    {
        foreach (['translations', 'approval_workflows', 'script_versions', 'knowledge_articles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
