<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class StandaloneMongodb extends BaseDatabaseModel
{
    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'mongodb-configdb-'.$database->uuid,
                'mount_path' => '/data/configdb',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true,
            ]);
            LocalPersistentVolume::create([
                'name' => 'mongodb-db-'.$database->uuid,
                'mount_path' => '/data/db',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true,
            ]);
        });
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = $this->image.$this->ports_mappings.$this->mongo_conf;
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

    public function mongoInitdbRootPassword(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                try {
                    return decrypt($value);
                } catch (\Throwable $th) {
                    $this->mongo_initdb_root_password = encrypt($value);
                    $this->save();

                    return $value;
                }
            }
        );
    }

    public function type(): string
    {
        return 'standalone-mongodb';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: fn () => "mongodb://{$this->mongo_initdb_root_username}:{$this->mongo_initdb_root_password}@{$this->uuid}:27017/?directConnection=true",
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    return "mongodb://{$this->mongo_initdb_root_username}:{$this->mongo_initdb_root_password}@{$this->destination->server->getIp}:{$this->public_port}/?directConnection=true";
                }

                return null;
            }
        );
    }

    public function isBackupSolutionAvailable()
    {
        return true;
    }
}
