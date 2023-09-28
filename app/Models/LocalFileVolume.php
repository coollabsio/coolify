<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class LocalFileVolume extends BaseModel
{
    use HasFactory;
    protected $guarded = [];

    public function service()
    {
        return $this->morphTo('resource');
    }
}
