<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreeningAnswer extends Model
{
    protected $fillable = ['screening_id', 'screening_question_id', 'question_prompt', 'answer', 'answer_status', 'question_version', 'saved_at', 'recorded_by'];

    protected function casts(): array
    {
        return ['answer' => 'encrypted', 'saved_at' => 'immutable_datetime'];
    }
}
