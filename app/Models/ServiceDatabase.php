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
    public function saveFileVolumes()
    {
        saveFileVolumesHelper($this);
    }
    public function configurationRequired() {
        $required = false;
        foreach($this->fileStorages as $fileStorage) {
            if (!$fileStorage->is_directory && $fileStorage->content == null) {
                $required = true;
                break;
            }
        }
        return $required;
    }
}
