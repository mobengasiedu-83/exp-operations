<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Screening extends Model
{
    protected $fillable = ['case_id', 'screening_protocol_id', 'recorded_by', 'priority', 'complete', 'completed_at'];

    protected function casts(): array
    {
        return ['complete' => 'boolean', 'completed_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ScreeningAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ScreeningAnswer::class);
    }
}
