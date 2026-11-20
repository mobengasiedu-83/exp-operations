<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StructuredListenerLog extends Model
{
    protected $fillable = ['connection_id', 'level', 'event_name', 'channel', 'lag_seconds', 'backoff_seconds', 'message', 'context', 'logged_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'logged_at' => 'datetime'];
    }
}
