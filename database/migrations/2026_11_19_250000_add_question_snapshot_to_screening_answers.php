<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 hands durable answers to Call History. Each answer keeps the
     * question text as it was asked, alongside the question ID and protocol
     * version, so a later questionnaire change can never rewrite history.
     */
    public function up(): void
    {
        Schema::table('screening_answers', function (Blueprint $table): void {
            $table->text('question_prompt')->nullable()->after('screening_question_id');
        });

        DB::table('screening_answers')->whereNull('question_prompt')->orderBy('id')
            ->chunkById(200, function ($answers): void {
                $prompts = DB::table('screening_questions')
                    ->whereIn('id', $answers->pluck('screening_question_id'))
                    ->pluck('prompt', 'id');

                foreach ($answers as $answer) {
                    $prompt = $prompts[$answer->screening_question_id] ?? null;

                    if ($prompt !== null) {
                        DB::table('screening_answers')->where('id', $answer->id)
                            ->update(['question_prompt' => $prompt]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('screening_answers', function (Blueprint $table): void {
            $table->dropColumn('question_prompt');
        });
    }
};
