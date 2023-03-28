<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class Deployment extends Model
{
    protected $fillable = [
        'uuid',
        'type_id',
        'type_type',
        'activity_log_id',
    ];
    public function type()
    {
        return $this->morphTo();
    }
    public function activity()
    {
        return $this->belongsTo(Activity::class, 'activity_log_id');
    }
}
