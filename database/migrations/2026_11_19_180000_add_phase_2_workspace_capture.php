<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 keeps autosaved questionnaire answers separate from the durable
     * clinical screening record, stamps recorded answers with their question
     * version and author, and stores the masked inbound line seen by the AMI
     * listener so the agent screen can show the line without storing caller IDs.
     */
    public function up(): void
    {
        Schema::create('screening_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('screening_protocol_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 20);
            $table->unsignedInteger('revision')->default(1);
            $table->json('answers');
            $table->timestamp('draft_saved_at');
            $table->timestamps();
            $table->unique(['case_id', 'screening_protocol_id']);
        });

        Schema::table('screening_answers', function (Blueprint $table): void {
            $table->string('question_version', 32)->nullable()->after('answer_status');
            $table->foreignId('recorded_by')->nullable()->after('saved_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('call_legs', function (Blueprint $table): void {
            $table->string('inbound_masked', 64)->nullable()->after('entry_kind');
        });
    }

    public function down(): void
    {
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->dropColumn('inbound_masked');
        });

        Schema::table('screening_answers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn('question_version');
        });

        Schema::dropIfExists('screening_drafts');
    }
};
