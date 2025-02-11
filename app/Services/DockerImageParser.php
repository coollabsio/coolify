<?php

namespace App\Services;

class DockerImageParser
{
    private string $registryUrl = '';

    private string $imageName = '';

    private string $tag = 'latest';

    public function parse(string $imageString): self
    {
        // First split by : to handle the tag, but be careful with registry ports
        $lastColon = strrpos($imageString, ':');
        $hasSlash = str_contains($imageString, '/');

        // If the last colon appears after the last slash, it's a tag
        // Otherwise it might be a port in the registry URL
        if ($lastColon !== false && (! $hasSlash || $lastColon > strrpos($imageString, '/'))) {
            $mainPart = substr($imageString, 0, $lastColon);
            $this->tag = substr($imageString, $lastColon + 1);
        } else {
            $mainPart = $imageString;
            $this->tag = 'latest';
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

        return implode('/', $parts).':'.$this->tag;
    }
}
