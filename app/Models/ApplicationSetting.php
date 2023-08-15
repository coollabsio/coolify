<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    protected $cast = [
        'is_static' => 'boolean',
        'is_auto_deploy_enabled' => 'boolean',
        'is_force_https_enabled' => 'boolean',
        'is_debug_enabled' => 'boolean',
        'is_preview_deployments_enabled' => 'boolean',
        'is_git_submodules_enabled' => 'boolean',
        'is_git_lfs_enabled' => 'boolean',
    ];
    protected $fillable = [
        'application_id',
        'is_static',
        'is_auto_deploy_enabled',
        'is_force_https_enabled',
        'is_debug_enabled',
        'is_preview_deployments_enabled',
        'is_git_submodules_enabled',
        'is_git_lfs_enabled',
    ];

    public function isStatic(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($this->application->ports_exposes)) {
                    if ($value) {
                        $this->application->ports_exposes = '80';
                    } else {
                        $this->application->ports_exposes = '3000';
                    }
                    $this->application->save();
                }
                return $value;
            }
        );
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
