<?php

namespace App\Models;

use Database\Factories\ConsentRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConsentRecord extends Model
{
    /** @use HasFactory<ConsentRecordFactory> */
    use HasFactory;

    protected $fillable = ['case_id', 'screening_protocol_id', 'recorded_by', 'status', 'callback_allowed', 'recorded_at'];

    protected function casts(): array
    {
        return ['callback_allowed' => 'boolean', 'recorded_at' => 'immutable_datetime'];
    }
}
