<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend the versioned questionnaire definition with answer types, required and
     * conditional rules, safety rules and explicit unanswered/unknown answer states.
     */
    public function up(): void
    {
        Schema::table('screening_questions', function (Blueprint $table): void {
            $table->string('type', 32)->default('yes_no_unknown')->after('prompt');
            $table->json('options')->nullable()->after('type');
            $table->boolean('required')->default(true)->after('options');
            $table->text('help_text')->nullable()->after('required');
            $table->string('safety_rule', 32)->nullable()->after('help_text');
            $table->unsignedBigInteger('conditional_question_id')->nullable()->after('safety_rule');
            $table->string('conditional_answer', 100)->nullable()->after('conditional_question_id');
        });

        Schema::table('screening_answers', function (Blueprint $table): void {
            $table->string('answer_status', 20)->default('answered')->after('answer');
            $table->timestamp('saved_at')->nullable()->after('answer_status');
        });
    }

    public function down(): void
    {
        Schema::table('screening_answers', function (Blueprint $table): void {
            $table->dropColumn(['answer_status', 'saved_at']);
        });

        Schema::table('screening_questions', function (Blueprint $table): void {
            $table->dropColumn(['type', 'options', 'required', 'help_text', 'safety_rule', 'conditional_question_id', 'conditional_answer']);
        });
    }
};
