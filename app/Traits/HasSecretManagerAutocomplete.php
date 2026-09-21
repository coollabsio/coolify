<?php

namespace App\Traits;

use App\Models\SecretManagerLink;

/**
 * Exposes the resource's secret manager source to the env-var-input
 * autocomplete: a boolean for the "vault" scope, and a lazy key-name fetch
 * (called from the frontend only when the user types a vault reference).
 * Secret values never reach the component state — key names only.
 */
trait HasSecretManagerAutocomplete
{
    public function hasSecretManagerSource(): bool
    {
        return $this->secretManagerLinkForAutocomplete() !== null;
    }

    /**
     * @return list<string>
     */
    public function fetchSecretManagerKeys(): array
    {
        $this->skipRender();

        $link = $this->secretManagerLinkForAutocomplete();

        if (! $link) {
            return [];
        }

        try {
            $this->authorize('view', $link->resourceable);
            $keys = array_keys($link->fetchSecrets());
            sort($keys);

            return $keys;
        } catch (\Throwable) {
            throw new \RuntimeException('Unable to fetch secret manager keys.');
        }
    }

    private function secretManagerLinkForAutocomplete(): ?SecretManagerLink
    {
        $resource = $this->secretManagerResource();

        if (! $resource || ! method_exists($resource, 'secretManagerLink')) {
            return null;
        }

        if (! $resource->relationLoaded('secretManagerLink')) {
            $resource->load('secretManagerLink.integrationToken');
        }

        return $resource->secretManagerLink;
    }
}
