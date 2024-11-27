<?php

namespace App\Actions\Server;

use App\Models\Server;
use Exception;

class ValidateServer
{
    public function validateConnection(Server $server, bool $justCheckingNewKey = false): array
    {
        config()->set('constants.ssh.mux_enabled', false);

        try {
            instant_remote_process(['ls /'], $server);
            if ($server->settings->is_reachable === false) {
                $server->settings->is_reachable = true;
                $server->settings->save();
            }

            $server->update(['validation_logs' => null]);

            return ['uptime' => true, 'error' => null];
        } catch (\Throwable $e) {
            if ($justCheckingNewKey) {
                return ['uptime' => false, 'error' => __('server.key_not_valid')];
            }

            if ($server->settings->is_reachable === true) {
                $server->settings->is_reachable = false;
                $server->settings->save();
            }

            $errorMessage = __('server.not_reachable').'<br><br><div class="text-error">Error: '.$e->getMessage().'</div>';
            $server->update(['validation_logs' => $errorMessage]);

            return ['uptime' => false, 'error' => $e->getMessage()];
        }
    }

    public function validateOS(Server $server): array
    {
        try {
            $os_release = instant_remote_process(['cat /etc/os-release'], $server);
            $releaseLines = collect(explode("\n", $os_release));

            $collectedData = $releaseLines->reduce(function ($carry, $line) {
                $item = str($line)->trim();
                $key = $item->before('=')->value();
                $value = $item->after('=')->lower()->replace('"', '')->value();
                $carry[$key] = $value;

                return $carry;
            }, []);

            $ID = data_get($collectedData, 'ID');

            $supported = collect(SUPPORTED_OS)
                ->first(fn ($supportedOs) => str($supportedOs)->contains($ID));

            if (! $supported) {
                $errorMessage = __('server.os_not_supported');
                $server->update(['validation_logs' => $errorMessage]);

                return ['supported' => false, 'error' => $errorMessage];
            }

            return ['supported' => true, 'os_type' => str($supported), 'error' => null];
        } catch (\Exception $e) {
            $errorMessage = __('server.os_check_failed');
            $server->update(['validation_logs' => $errorMessage]);

            return ['supported' => false, 'error' => $errorMessage];
        }
    }

    public function validateDockerEngine(Server $server, bool $throwError = false): array
    {
        try {
            $dockerBinary = instant_remote_process(['command -v docker'], $server, false, no_sudo: true);
            if (is_null($dockerBinary)) {
                $server->settings->is_usable = false;
                $server->settings->save();

                $errorMessage = __('server.docker_not_installed');
                $server->update(['validation_logs' => $errorMessage]);

                return ['installed' => false, 'error' => $errorMessage];
            }

            instant_remote_process(['docker version'], $server);

            $server->settings->is_usable = true;
            $server->settings->save();
            $this->validateCoolifyNetwork($server);

            return ['installed' => true, 'error' => null];
        } catch (\Throwable $e) {
            $server->settings->is_usable = false;
            $server->settings->save();

            $errorMessage = __('server.docker_not_running');
            $server->update(['validation_logs' => $errorMessage]);

            return ['installed' => false, 'error' => $errorMessage];
        }
    }

    public function validateDockerCompose(Server $server): array
    {
        try {
            $dockerCompose = instant_remote_process(['docker compose version'], $server, false);
            if (is_null($dockerCompose)) {
                $server->settings->is_usable = false;
                $server->settings->save();

                $errorMessage = __('server.docker_compose_not_installed');
                $server->update(['validation_logs' => $errorMessage]);

                return ['installed' => false, 'error' => $errorMessage];
            }

            $server->settings->is_usable = true;
            $server->settings->save();

            return ['installed' => true, 'error' => null];
        } catch (\Throwable $e) {
            $errorMessage = __('server.docker_compose_check_failed');
            $server->update(['validation_logs' => $errorMessage]);

            return ['installed' => false, 'error' => $errorMessage];
        }
    }

    public function validateDockerEngineVersion(Server $server): array
    {
        try {
            $dockerVersionRaw = instant_remote_process(['docker version --format json'], $server, false);
            $dockerVersionJson = json_decode($dockerVersionRaw, true);
            $dockerVersion = data_get($dockerVersionJson, 'Server.Version', '0.0.0');
            $dockerVersion = checkMinimumDockerEngineVersion($dockerVersion);

            if (is_null($dockerVersion)) {
                $server->settings->is_usable = false;
                $server->settings->save();

                $errorMessage = __('server.docker_engine_not_installed');
                $server->update(['validation_logs' => $errorMessage]);

                return ['valid' => false, 'error' => $errorMessage];
            }

            $server->settings->is_reachable = true;
            $server->settings->is_usable = true;
            $server->settings->save();

            return ['valid' => true, 'error' => null];
        } catch (\Throwable $e) {
            $errorMessage = __('server.docker_version_check_failed');
            $server->update(['validation_logs' => $errorMessage]);

            return ['valid' => false, 'error' => $errorMessage];
        }
    }

    public function validateCoolifyNetwork(Server $server, bool $isSwarm = false, bool $isBuildServer = false): ?bool
    {
        if ($isBuildServer) {
            return null;
        }
        if ($isSwarm) {
            return instant_remote_process(['docker network create --attachable --driver overlay coolify-overlay >/dev/null 2>&1 || true'], $server, false);
        } else {
            return instant_remote_process(['docker network create coolify --attachable >/dev/null 2>&1 || true'], $server, false);
        }
    }

    public function validateDockerSwarm(Server $server): bool
    {
        $swarmStatus = instant_remote_process(['docker info|grep -i swarm'], $server, false);
        $swarmStatus = str($swarmStatus)->trim()->after(':')->trim();
        if ($swarmStatus === 'inactive') {
            throw new Exception(__('server.swarm_not_initiated'));
        }
        $server->settings->is_usable = true;
        $server->settings->save();
        $this->validateCoolifyNetwork($server, isSwarm: true);

        return true;
    }
}
