<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $report_run_id
 * @property string $format
 * @property string $disk
 * @property string $path
 * @property string $checksum
 * @property int $size_bytes
 * @property int $row_count
 * @property int|null $requested_by
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $downloaded_at
 * @property int $download_count
 */
class ReportExport extends Model
{
    protected $table = 'exports';

    public const EXPIRY_DAYS = 7;

    protected $fillable = [
        'report_run_id', 'format', 'disk', 'path', 'checksum', 'size_bytes',
        'row_count', 'requested_by', 'expires_at', 'downloaded_at', 'download_count',
    ];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'downloaded_at' => 'immutable_datetime',
        'size_bytes' => 'integer',
        'row_count' => 'integer',
        'download_count' => 'integer',
    ];

    /** @return BelongsTo<ReportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReportRun::class, 'report_run_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
