<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationDeploymentQueue extends Model
{
    protected $fillable = [
        'application_id',
        'deployment_uuid',
        'pull_request_id',
        'force_rebuild',
        'commit',
        'status',
        'is_webhook',
    ];
}
