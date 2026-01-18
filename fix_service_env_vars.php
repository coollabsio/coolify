<?php

/**
 * FIXED VERSION of saveComposeConfigs() method for app/Models/Service.php
 * 
 * This fixes issue #7655 where environment variables were leaking across container boundaries.
 * 
 * TO APPLY: Replace the saveComposeConfigs() method in app/Models/Service.php
 * (around line 1493) with this version.
 */

public function saveComposeConfigs()
{
    // Guard against null or empty docker_compose
    if (! $this->docker_compose) {
        return;
    }

    $workdir = $this->workdir();

    instant_remote_process([
        "mkdir -p $workdir",
        "cd $workdir",
    ], $this->server);

    $filename = new Cuid2.'-docker-compose.yml';
    Storage::disk('local')->put("tmp/{$filename}", $this->docker_compose);
    $path = Storage::path("tmp/{$filename}");
    instant_scp($path, "{$workdir}/docker-compose.yml", $this->server);
    Storage::disk('local')->delete("tmp/{$filename}");

    $commands[] = "cd $workdir";
    $commands[] = 'rm -f .env || true';

    $envs = collect([]);

    // Generate SERVICE_NAME_* environment variables from docker-compose services
    if ($this->docker_compose) {
        try {
            $dockerCompose = \Symfony\Component\Yaml\Yaml::parse($this->docker_compose);
            $services = data_get($dockerCompose, 'services', []);
            foreach ($services as $serviceName => $_) {
                $envs->push('SERVICE_NAME_'.str($serviceName)->replace('-', '_')->replace('.', '_')->upper().'='.$serviceName);
            }
        } catch (\Exception $e) {
            ray($e->getMessage());
        }
    }

    // FIX for #7655: Only include Service-level environment variables in the shared .env file
    // Container-specific variables (ServiceApplication/ServiceDatabase) should NOT be here
    // as they are already injected into their respective containers via docker-compose.yml
    
    // Get IDs of all container-specific environment variables to exclude them
    $containerSpecificEnvIds = collect([]);
    
    // Collect env var IDs from all ServiceApplications
    foreach ($this->applications as $app) {
        $containerSpecificEnvIds = $containerSpecificEnvIds->merge(
            $app->environment_variables()->pluck('id')
        );
    }
    
    // Collect env var IDs from all ServiceDatabases
    foreach ($this->databases as $db) {
        $containerSpecificEnvIds = $containerSpecificEnvIds->merge(
            $db->environment_variables()->pluck('id')
        );
    }

    // Only get Service-level environment variables (exclude container-specific ones)
    $envs_from_coolify = $this->environment_variables()
        ->whereNotIn('id', $containerSpecificEnvIds->toArray())
        ->get();
        
    $sorted = $envs_from_coolify->sortBy(function ($env) {
        if (str($env->key)->startsWith('SERVICE_')) {
            return 1;
        }
        if (str($env->value)->startsWith('$SERVICE_') || str($env->value)->startsWith('${SERVICE_')) {
            return 2;
        }

        return 3;
    });
    foreach ($sorted as $env) {
        $envs->push("{$env->key}={$env->real_value}");
    }
    if ($envs->count() === 0) {
        $commands[] = 'touch .env';
    } else {
        $envs_base64 = base64_encode($envs->implode("\n"));
        $commands[] = "echo '$envs_base64' | base64 -d | tee .env > /dev/null";
    }

    instant_remote_process($commands, $this->server);
}
