<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    protected $fillable = [
        'application_id',
        'is_git_submodules_allowed',
        'is_git_lfs_allowed',
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
