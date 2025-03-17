<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class StandaloneDragonfly extends BaseDatabaseModel
{
    protected $casts = [
        'dragonfly_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'dragonfly-data-'.$database->uuid,
                'mount_path' => '/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true,
            ]);
        });
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = $this->image.$this->ports_mappings;
        $newConfigHash .= json_encode($this->environmentVariables()->get('value')->sort());
        $newConfigHash = md5($newConfigHash);
        $oldConfigHash = data_get($this, 'config_hash');
        if ($oldConfigHash === null) {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
        if ($oldConfigHash === $newConfigHash) {
            return false;
        } else {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
    }

    public function type(): string
    {
        return 'standalone-dragonfly';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: fn () => "redis://:{$this->dragonfly_password}@{$this->uuid}:6379/0",
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    return "redis://:{$this->dragonfly_password}@{$this->destination->server->getIp}:{$this->public_port}/0";
                }

                return null;
            }
        );
    }

    public function isBackupSolutionAvailable()
    {
        return false;
    }
}
