<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class StandaloneMysql extends BaseDatabaseModel
{
    protected $casts = [
        'mysql_password' => 'encrypted',
        'mysql_root_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'mysql-data-'.$database->uuid,
                'mount_path' => '/var/lib/mysql',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true,
            ]);
        });
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = $this->image.$this->ports_mappings.$this->mysql_conf;
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
        return 'standalone-mysql';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: fn () => "mysql://{$this->mysql_user}:{$this->mysql_password}@{$this->uuid}:3306/{$this->mysql_database}",
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    return "mysql://{$this->mysql_user}:{$this->mysql_password}@{$this->destination->server->getIp}:{$this->public_port}/{$this->mysql_database}";
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
