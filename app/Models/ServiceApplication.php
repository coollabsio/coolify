<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceApplication extends BaseModel
{
    use HasFactory;
    protected $guarded = [];

    public function type()
    {
        return 'service';
    }
    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }
}
