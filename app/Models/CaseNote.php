<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseNote extends Model
{
    protected $fillable = ['case_id', 'recorded_by', 'body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
