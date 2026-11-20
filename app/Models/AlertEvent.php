<?php

namespace App\Models;

use Database\Factories\AlertEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $details
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $resolved_at
 * @property AlertRule|null $rule
 */
class AlertEvent extends Model
{
    /** @use HasFactory<AlertEventFactory> */
    use HasFactory;

    protected $fillable = [
        'alert_rule_id', 'pbx_connection_id', 'interaction_id', 'fingerprint',
        'status', 'severity', 'subject', 'details', 'first_seen_at', 'last_seen_at',
        'acknowledged_at', 'acknowledged_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'acknowledged_by' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /** @return BelongsTo<PbxConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PbxConnection::class, 'pbx_connection_id');
    }

    /** @return BelongsTo<Interaction, $this> */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
