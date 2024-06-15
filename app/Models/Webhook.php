<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $status
 * @property string $type
 * @property mixed $payload
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook query()
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook whereFailureReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Webhook whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Webhook extends Model
{
    protected $guarded = [];

    protected $casts = [
        'type' => 'string',
        'payload' => 'encrypted',
    ];
}
