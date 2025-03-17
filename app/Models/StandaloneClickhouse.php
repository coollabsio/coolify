<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class StandaloneClickhouse extends BaseDatabaseModel
{
    protected $casts = [
        'clickhouse_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'clickhouse-data-'.$database->uuid,
                'mount_path' => '/bitnami/clickhouse',
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
        return 'standalone-clickhouse';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: fn () => "clickhouse://{$this->clickhouse_admin_user}:{$this->clickhouse_admin_password}@{$this->uuid}:9000/{$this->clickhouse_db}",
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    return "clickhouse://{$this->clickhouse_admin_user}:{$this->clickhouse_admin_password}@{$this->destination->server->getIp}:{$this->public_port}/{$this->clickhouse_db}";
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
