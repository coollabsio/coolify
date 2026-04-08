<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UpdateNotificationReportState extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_reported_at' => 'datetime',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
