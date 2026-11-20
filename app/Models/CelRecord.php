<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $event_type
 * @property string|null $event_time
 * @property string|null $uniqueid
 * @property string|null $linkedid
 * @property string|null $tenantid
 * @property string|null $peer
 * @property string|null $peerinbound
 * @property string|null $peeroutbound
 * @property string|null $peerport
 * @property string|null $peerformat
 * @property string|null $peerhost
 * @property string|null $channel
 * @property string|null $channelstate
 * @property string|null $channelstatedesc
 * @property string|null $calleridnum
 * @property string|null $calleridname
 * @property string|null $associateduniqueid
 * @property string|null $associatedcalleeidnum
 * @property string|null $associatedcalleeidname
 * @property string|null $accountcode
 * @property string|null $exten
 * @property string|null $context
 * @property string|null $appname
 * @property string|null $appdata
 * @property string|null $amaflags
 * @property string|null $account
 * @property array<string, mixed>|null $raw
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
class CelRecord extends Model
{
    protected $fillable = [
        'event_type',
        'event_time',
        'uniqueid',
        'linkedid',
        'tenantid',
        'peer',
        'peerinbound',
        'peeroutbound',
        'peerport',
        'peerformat',
        'peerhost',
        'channel',
        'channelstate',
        'channelstatedesc',
        'calleridnum',
        'calleridname',
        'associateduniqueid',
        'associatedcalleeidnum',
        'associatedcalleeidname',
        'accountcode',
        'exten',
        'context',
        'appname',
        'appdata',
        'amaflags',
        'account',
        'raw',
    ];

    protected $casts = [
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
