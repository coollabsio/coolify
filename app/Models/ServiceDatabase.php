<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Symfony\Component\Yaml\Yaml;

class ServiceDatabase extends BaseModel
{
    use HasFactory;
    protected $guarded = [];

    public function type()
    {
        return 'service';
    }
    public function service()
    {
        return $this->belongsTo(Service::class);
    }
    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }
    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }
    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }
}
