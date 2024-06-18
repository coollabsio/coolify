<?php

namespace App\Domain\Deployment;

use Illuminate\Support\Collection;

class DeploymentResult
{
    private ?string $dockerComposeBase64;

    private ?string $dockerComposeLocation;

    public Collection $savedLogs;
    private string $fullHealthCheckUrl;
    private string $dockerComposeGenerated;

    public function __construct()
    {
        $this->savedLogs = collect();
    }

    public function getDockerComposeBase64(): ?string
    {
        return $this->dockerComposeBase64;
    }

    public function setDockerComposeBase64(string $dockerComposeBase64): void
    {
        $this->dockerComposeBase64 = $dockerComposeBase64;
    }

    public function getDockerComposeLocation(): ?string
    {
        return $this->dockerComposeLocation;
    }

    public function setDockerComposeLocation(string $dockerComposeLocation): void
    {
        $this->dockerComposeLocation = $dockerComposeLocation;
    }

    public function setFullHealthCheckUrl(string $fullHealthcheckUrl)
    {
        $this->fullHealthCheckUrl = $fullHealthcheckUrl;
    }

    public function setDockerCompose(string $dockerComposeGenerated)
    {
        $this->dockerComposeGenerated = $dockerComposeGenerated;
    }
}
