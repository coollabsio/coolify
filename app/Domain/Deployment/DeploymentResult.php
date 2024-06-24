<?php

namespace App\Domain\Deployment;

use Illuminate\Support\Collection;

class DeploymentResult
{
    private ?string $dockerComposeBase64 = null;

    private string $dockerComposeLocation = '/docker-compose.yml';

    public Collection $savedLogs;

    private ?string $fullHealthCheckUrl = null;

    private ?string $dockerComposeGenerated = null;

    private ?string $nixpacksPlanJson = null;

    private ?bool $newVersionIsHealthy = null;

    private string $dockerFileLocation = '/Dockerfile';

    private ?string $dockerComposeCustomStartCommand = null;

    private ?string $dockerComposeCustomBuildCommand = null;

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

    public function getDockerComposeLocation(): string
    {
        return $this->dockerComposeLocation;
    }

    public function setDockerComposeLocation(string $dockerComposeLocation): void
    {
        $this->dockerComposeLocation = $dockerComposeLocation;
    }

    public function setFullHealthCheckUrl(string $fullHealthcheckUrl): void
    {
        $this->fullHealthCheckUrl = $fullHealthcheckUrl;
    }

    public function setDockerCompose(string $dockerComposeGenerated): void
    {
        $this->dockerComposeGenerated = $dockerComposeGenerated;
    }

    public function setNixpacksPlanJson(string $nixpacksPlanEncoded): void
    {
        $this->nixpacksPlanJson = $nixpacksPlanEncoded;
    }

    public function getNixpacksPlanJson(): ?string
    {
        return $this->nixpacksPlanJson;
    }

    public function isNewVersionHealth(): ?bool
    {
        return $this->newVersionIsHealthy;
    }

    public function setNewVersionHealthy(bool $isHealthy): void
    {
        $this->newVersionIsHealthy = $isHealthy;
    }

    public function getDockerfileLocation(): string
    {
        return $this->dockerFileLocation;
    }

    public function setDockerFileLocation(string $dockerFileLocation): void
    {
        $this->dockerFileLocation = $dockerFileLocation;
    }

    public function setDockerComposeCustomStartCommand(string $startCommand): void
    {
        $this->dockerComposeCustomStartCommand = $startCommand;
    }

    public function getDockerComposeCustomStartCommand(): ?string
    {
        return $this->dockerComposeCustomStartCommand;
    }

    public function setDockerComposeCustomBuildCommand(string $buildCommand): void
    {
        $this->dockerComposeCustomBuildCommand = $buildCommand;
    }

    public function getDockerComposeCustomBuildCommand(): ?string
    {
        return $this->dockerComposeCustomBuildCommand;
    }
}
