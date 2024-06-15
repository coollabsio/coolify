<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property mixed|null $value
 * @property bool $is_shown_once
 * @property string $type
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $environment_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $is_multiline
 * @property string $version
 * @property bool $is_literal
 *
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable query()
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereIsLiteral($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereIsMultiline($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereIsShownOnce($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SharedEnvironmentVariable whereVersion($value)
 *
 * @mixin \Eloquent
 */
class SharedEnvironmentVariable extends Model
{
    protected $guarded = [];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
    ];
}
