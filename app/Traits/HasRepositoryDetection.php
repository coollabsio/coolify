<?php

namespace App\Traits;

use App\Data\RepositoryDetectionResult;

trait HasRepositoryDetection
{
    public bool $detectionRan = false;

    public array $detectedDockerfiles = [];

    public array $detectedDockerComposeFiles = [];

    public ?string $selectedDockerfile = null;

    public ?int $detectedPort = null;

    public array $dockerfilePorts = [];

    public array $detectedEnvFiles = [];

    public ?string $selectedEnvFile = null;

    public array $parsedEnvFiles = [];

    public array $envExampleVars = [];

    public bool $envImported = false;

    protected function applyDetectionResult(RepositoryDetectionResult $result): void
    {
        $this->detectedDockerfiles = $result->dockerfiles;
        $this->detectedDockerComposeFiles = $result->dockerComposeFiles;
        $this->dockerfilePorts = $result->dockerfilePorts;

        $suggestedBuildPack = $result->getSuggestedBuildPack()->value;
        if ($suggestedBuildPack !== $this->build_pack) {
            $this->build_pack = $suggestedBuildPack;
            $this->updatedBuildPack();
        }

        if ($result->hasDockerfile()) {
            $this->selectedDockerfile = $result->dockerfiles[0];
            $port = $result->dockerfilePorts[$this->selectedDockerfile] ?? null;
            if ($port) {
                $this->port = $port;
                $this->detectedPort = $port;
            }
        }

        if ($result->hasDockerCompose() && count($result->dockerComposeFiles) === 1) {
            $this->docker_compose_location = '/'.$result->dockerComposeFiles[0];
        }

        if ($result->hasEnvFiles()) {
            $this->detectedEnvFiles = array_keys($result->envFiles);
            $this->parsedEnvFiles = [];
            foreach ($result->envFiles as $filename => $content) {
                $this->parsedEnvFiles[$filename] = $content !== null
                    ? parseEnvFormatToArray($content)
                    : [];
            }
            $this->selectedEnvFile = $this->detectedEnvFiles[0];
            $this->envExampleVars = $this->parsedEnvFiles[$this->selectedEnvFile] ?? [];
        }
    }

    public function updatedSelectedDockerfile(): void
    {
        if ($this->selectedDockerfile && isset($this->dockerfilePorts[$this->selectedDockerfile])) {
            $port = $this->dockerfilePorts[$this->selectedDockerfile];
            if ($port) {
                $this->port = $port;
                $this->detectedPort = $port;
            }
        }
    }

    public function updatedSelectedEnvFile(): void
    {
        if ($this->selectedEnvFile && isset($this->parsedEnvFiles[$this->selectedEnvFile])) {
            $this->envExampleVars = $this->parsedEnvFiles[$this->selectedEnvFile];
            $this->envImported = false;
        }
    }

    public function confirmEnvImport(): void
    {
        $this->envImported = true;
    }

    public function clearEnvVars(): void
    {
        $this->envImported = false;
        $this->envExampleVars = $this->parsedEnvFiles[$this->selectedEnvFile] ?? [];
    }
}
