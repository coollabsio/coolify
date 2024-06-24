<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $uuid
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Kubernetes newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Kubernetes newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Kubernetes query()
 * @method static \Illuminate\Database\Eloquent\Builder|Kubernetes whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Kubernetes whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Kubernetes whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Kubernetes whereUuid($value)
 *
 * @mixin \Eloquent
 */
class Kubernetes extends BaseModel {}
