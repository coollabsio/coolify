<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class StandalonePostgresql extends BaseDatabaseModel
{
    protected $casts = [
        'init_scripts' => 'array',
        'postgres_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'postgres-data-'.$database->uuid,
                'mount_path' => '/var/lib/postgresql/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true,
            ]);
        });
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = $this->image.$this->ports_mappings.$this->postgres_initdb_args.$this->postgres_host_auth_method;
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
        return 'standalone-postgresql';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: fn () => "postgres://{$this->postgres_user}:{$this->postgres_password}@{$this->uuid}:5432/{$this->postgres_db}",
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    return "postgres://{$this->postgres_user}:{$this->postgres_password}@{$this->destination->server->getIp}:{$this->public_port}/{$this->postgres_db}";
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
