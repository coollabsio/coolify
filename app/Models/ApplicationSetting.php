<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    protected $fillable = [
        'application_id',
        'is_git_submodules_allowed',
        'is_git_lfs_allowed',
    ];
    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
