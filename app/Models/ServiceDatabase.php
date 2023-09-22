<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceDatabase extends BaseModel
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
