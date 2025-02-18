<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class StandaloneRedis extends BaseDatabaseModel
{
    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'redis-data-'.$database->uuid,
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
        $newConfigHash = $this->image.$this->ports_mappings.$this->redis_conf;
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
        return 'standalone-redis';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $redis_version = $this->getRedisVersion();
                $username_part = version_compare($redis_version, '6.0', '>=') ? "{$this->redis_username}:" : '';

                return "redis://{$username_part}{$this->redis_password}@{$this->uuid}:6379/0";
            }
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    $redis_version = $this->getRedisVersion();
                    $username_part = version_compare($redis_version, '6.0', '>=') ? "{$this->redis_username}:" : '';

                    return "redis://{$username_part}{$this->redis_password}@{$this->destination->server->getIp}:{$this->public_port}/0";
                }

                return null;
            }
        );
    }

    public function getRedisVersion()
    {
        $image_parts = explode(':', $this->image);

        return $image_parts[1] ?? '0.0';
    }

    public function isBackupSolutionAvailable()
    {
        return false;
    }

    public function redisPassword(): Attribute
    {
        return new Attribute(
            get: function () {
                $password = $this->runtimeEnvironmentVariables()->where('key', 'REDIS_PASSWORD')->first();
                if (! $password) {
                    return null;
                }

                return $password->value;
            },

        );
    }

    public function redisUsername(): Attribute
    {
        return new Attribute(
            get: function () {
                $username = $this->runtimeEnvironmentVariables()->where('key', 'REDIS_USERNAME')->first();
                if (! $username) {
                    return null;
                }

                return $username->value;
            }
        );
    }
}
