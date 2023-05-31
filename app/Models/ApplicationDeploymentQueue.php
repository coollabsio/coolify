<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

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
