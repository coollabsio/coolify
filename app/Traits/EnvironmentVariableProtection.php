<?php

namespace App\Traits;

use Symfony\Component\Yaml\Yaml;

trait EnvironmentVariableProtection
{
    /**
     * Check if an environment variable is protected from deletion
     *
     * @param  string  $key  The environment variable key to check
     * @return bool True if the variable is protected, false otherwise
     */
    protected function isProtectedEnvironmentVariable(string $key): bool
    {
        return str($key)->startsWith('SERVICE_FQDN_') || str($key)->startsWith('SERVICE_URL_') || str($key)->startsWith('SERVICE_NAME_');
    }

    /**
     * Check if an environment variable is used in Docker Compose
     *
     * @param  string  $key  The environment variable key to check
     * @param  string|null  $dockerCompose  The Docker Compose YAML content
     * @return array [bool $isUsed, string $reason] Whether the variable is used and the reason if it is
     */
    protected function isEnvironmentVariableUsedInDockerCompose(string $key, ?string $dockerCompose): array
    {
        if (empty($dockerCompose)) {
            return [false, ''];
        }

        try {
            $dockerComposeData = Yaml::parse($dockerCompose);
            $dockerEnvVars = data_get($dockerComposeData, 'services.*.environment');

            foreach ($dockerEnvVars as $serviceEnvs) {
                if (! is_array($serviceEnvs)) {
                    continue;
                }

                // Check for direct variable usage
                foreach ($serviceEnvs as $env => $value) {
                    if ($env === $key) {
                        return [true, "Environment variable '{$key}' is used directly in the Docker Compose file."];
                    }
                }

                // Check for variable references in values
                foreach ($serviceEnvs as $env => $value) {
                    if (is_string($value) && str_contains($value, '$'.$key)) {
                        return [true, "Environment variable '{$key}' is referenced in the Docker Compose file."];
                    }
                }
            }
        } catch (\Exception $e) {
            // If there's an error parsing the Docker Compose file, we'll assume it's not used
            return [false, ''];
        }

        return [false, ''];
    }
}
