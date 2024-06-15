<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property string $email
 * @property bool $verified
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist query()
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Waitlist whereVerified($value)
 *
 * @mixin \Eloquent
 */
class Waitlist extends BaseModel
{
    use HasFactory;

    protected $guarded = [];
}
