<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Project|null $project
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectSetting whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectSetting whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProjectSetting extends Model
{
    protected $guarded = [];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
