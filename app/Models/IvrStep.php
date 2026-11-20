<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IvrStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One key press the caller made on the inbound IVR menu.
 *
 * The row is projected from PBX evidence only. A call whose menu steps were
 * never received simply has no row, so the journey screen can say "not
 * recorded" instead of guessing which option the caller chose.
 *
 * @property string|null $menu
 * @property string|null $option
 * @property CarbonImmutable|null $occurred_at
 */
class IvrStep extends Model
{
    /** @use HasFactory<IvrStepFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'interaction_id', 'pbx_connection_id', 'uniqueid', 'menu', 'option',
        'application', 'app_data', 'occurred_at', 'timing_quality', 'fingerprint',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Interaction, $this> */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }
}
