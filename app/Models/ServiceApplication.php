<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;

class ServiceApplication extends BaseModel
{
    use HasFactory;
    protected $guarded = [];

    public function type()
    {
        return 'service';
    }
    public function documentation()
    {
        $services = Cache::get('services', []);
        $service = data_get($services, $this->name, []);
        return data_get($service, 'documentation', 'https://coolify.io/docs');
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
    public function saveFileVolumes()
    {
        saveFileVolumesHelper($this);
    }
}
