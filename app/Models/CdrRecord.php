<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $accountcode
 * @property string|null $src
 * @property string|null $dst
 * @property string|null $dcontext
 * @property string|null $clrd
 * @property string|null $channel
 * @property string|null $dstchannel
 * @property string|null $lastapp
 * @property string|null $lastdata
 * @property \DateTimeInterface|null $start
 * @property \DateTimeInterface|null $answer
 * @property \DateTimeInterface|null $end
 * @property int|null $duration
 * @property int|null $billsec
 * @property string|null $disposition
 * @property string|null $amaflags
 * @property string|null $uniqueid
 * @property string|null $userfield
 * @property string|null $linkedid
 * @property array<string, mixed>|null $raw
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
class CdrRecord extends Model
{
    protected $fillable = [
        'accountcode',
        'src',
        'dst',
        'dcontext',
        'clrd',
        'channel',
        'dstchannel',
        'lastapp',
        'lastdata',
        'start',
        'answer',
        'end',
        'duration',
        'billsec',
        'disposition',
        'amaflags',
        'uniqueid',
        'userfield',
        'linkedid',
        'raw',
    ];

    protected $casts = [
        'start' => 'datetime',
        'answer' => 'datetime',
        'end' => 'datetime',
        'duration' => 'integer',
        'billsec' => 'integer',
        'raw' => 'array',
    ];

    /**
     * Scope to only records for a specific unique ID.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUniqueId(Builder $query, string $uniqueId): Builder
    {
        return $query->where('uniqueid', $uniqueId);
    }

    /**
     * Scope to only records for a specific linked ID.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForLinkedId(Builder $query, string $linkedId): Builder
    {
        return $query->where('linkedid', $linkedId);
    }
}
