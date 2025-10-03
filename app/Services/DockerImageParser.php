<?php

namespace App\Services;

class DockerImageParser
{
    private string $registryUrl = '';

    private string $imageName = '';

    private string $tag = 'latest';

    private bool $isImageHash = false;

    public function parse(string $imageString): self
    {
        // Check for @sha256: format first (e.g., nginx@sha256:abc123...)
        if (preg_match('/^(.+)@sha256:([a-f0-9]{64})$/i', $imageString, $matches)) {
            $mainPart = $matches[1];
            $this->tag = $matches[2];
            $this->isImageHash = true;
        } else {
            // Split by : to handle the tag, but be careful with registry ports
            $lastColon = strrpos($imageString, ':');
            $hasSlash = str_contains($imageString, '/');

            // If the last colon appears after the last slash, it's a tag
            // Otherwise it might be a port in the registry URL
            if ($lastColon !== false && (! $hasSlash || $lastColon > strrpos($imageString, '/'))) {
                $mainPart = substr($imageString, 0, $lastColon);
                $this->tag = substr($imageString, $lastColon + 1);

                // Check if the tag is a SHA256 hash
                $this->isImageHash = $this->isSha256Hash($this->tag);
            } else {
                $mainPart = $imageString;
                $this->tag = 'latest';
                $this->isImageHash = false;
            }
        }

        // Split the main part by / to handle registry and image name
        $pathParts = explode('/', $mainPart);

        // If we have more than one part and the first part contains a dot or colon
        // it's likely a registry URL
        if (count($pathParts) > 1 && (str_contains($pathParts[0], '.') || str_contains($pathParts[0], ':'))) {
            $this->registryUrl = array_shift($pathParts);
            $this->imageName = implode('/', $pathParts);
        } else {
            $this->imageName = $mainPart;
        }

        return $this;
    }

    /**
     * Check if the given string is a SHA256 hash
     */
    private function isSha256Hash(string $hash): bool
    {
        // SHA256 hashes are 64 characters long and contain only hexadecimal characters
        return preg_match('/^[a-f0-9]{64}$/i', $hash) === 1;
    }

    /**
     * Check if the current tag is an image hash
     */
    public function isImageHash(): bool
    {
        return $this->isImageHash;
    }

    /**
     * Get the full image name with hash if present
     */
    public function getFullImageNameWithHash(): string
    {
        $imageName = $this->getFullImageNameWithoutTag();

        if ($this->isImageHash) {
            return $imageName.'@sha256:'.$this->tag;
        }

        return $imageName.':'.$this->tag;
    }

    public function getFullImageNameWithoutTag(): string
    {
        if ($this->registryUrl) {
            return $this->registryUrl.'/'.$this->imageName;
        }

        return $this->imageName;
    }

    public function getRegistryUrl(): string
    {
        return $this->registryUrl;
    }

    public function getImageName(): string
    {
        return $this->imageName;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function toString(): string
    {
        $parts = [];
        if ($this->registryUrl) {
            $parts[] = $this->registryUrl;
        }
        $parts[] = $this->imageName;

        if ($this->isImageHash) {
            return implode('/', $parts).'@sha256:'.$this->tag;
        }

        return implode('/', $parts).':'.$this->tag;
    }
}
