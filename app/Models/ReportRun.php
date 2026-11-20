<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $report_definition_id
 * @property string $reference
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $source
 * @property string $status
 * @property int|null $generated_by
 * @property CarbonImmutable $generated_at
 * @property int $kpi_count
 * @property int $breakdown_count
 * @property array<string, mixed> $totals
 * @property array<string, mixed>|null $governance
 * @property string $checksum
 * @property int $revision
 */
class ReportRun extends Model
{
    public const SOURCES = ['manual', 'scheduled'];

    protected $fillable = [
        'report_definition_id', 'reference', 'period_start', 'period_end', 'source', 'status',
        'generated_by', 'generated_at', 'kpi_count', 'breakdown_count', 'totals', 'governance',
        'checksum', 'revision',
    ];

    protected $casts = [
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
        'generated_at' => 'immutable_datetime',
        'totals' => 'array',
        'governance' => 'array',
        'kpi_count' => 'integer',
        'breakdown_count' => 'integer',
        'revision' => 'integer',
    ];

    /** @return BelongsTo<ReportDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return HasMany<KpiSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(KpiSnapshot::class);
    }

    /** @return HasMany<ReportExport, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }
}
