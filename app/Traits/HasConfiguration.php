<?php

namespace App\Traits;

use App\Services\ConfigurationGenerator;

trait HasConfiguration
{
    public function generateConfigurationFiles(): void
    {
        $generator = new ConfigurationGenerator($this);

        $configDir = base_configuration_dir()."/{$this->uuid}";
        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $generator->saveJson($configDir.'/coolify.json');
        $generator->saveYaml($configDir.'/coolify.yaml');

        // Generate a README file with basic information
        file_put_contents(
            $configDir.'/README.md',
            generate_readme_file($this->name, now()->toIso8601String())
        );
    }

    public function getConfigurationAsJson(): string
    {
        return (new ConfigurationGenerator($this))->toJson();
    }

    public function getConfigurationAsYaml(): string
    {
        return (new ConfigurationGenerator($this))->toYaml();
    }

    public function getConfigurationAsArray(): array
    {
        return (new ConfigurationGenerator($this))->toArray();
    }
}
