<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $report_run_id
 * @property string $key
 * @property string $category
 * @property string $unit
 * @property float|null $value
 * @property float|null $numerator
 * @property float|null $denominator
 * @property list<string>|null $exclusions
 * @property string $definition
 * @property CarbonImmutable $computed_at
 */
class KpiSnapshot extends Model
{
    protected $fillable = [
        'report_run_id', 'key', 'category', 'unit', 'value', 'numerator',
        'denominator', 'exclusions', 'definition', 'computed_at',
    ];

    protected $casts = [
        'value' => 'float',
        'numerator' => 'float',
        'denominator' => 'float',
        'exclusions' => 'array',
        'computed_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<ReportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReportRun::class, 'report_run_id');
    }
}
