<?php

namespace App\Traits;

use App\Models\SecretManagerLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

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

    /** Seconds that fetched key names are reused for the same secret manager source. */
    private const SECRET_MANAGER_KEYS_CACHE_SECONDS = 60;

    /** Secret manager requests that one user can start from the autocomplete per minute. */
    private const SECRET_MANAGER_KEYS_REQUESTS_PER_MINUTE = 10;

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

        $this->authorize('view', $link->resourceable);

        // Only key names are cached. Another token or other settings use another cache entry.
        $cacheKey = 'secret-manager-keys:'.$link->id.':'.hash('sha256', json_encode([
            $link->integration_token_id,
            $link->settings,
            $link->integrationToken?->updated_at?->getTimestamp(),
        ]));
        $cachedKeys = Cache::get($cacheKey);
        if (is_array($cachedKeys)) {
            return $cachedKeys;
        }

        $rateLimitKey = 'secret-manager-keys:user:'.auth()->id();
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::SECRET_MANAGER_KEYS_REQUESTS_PER_MINUTE)) {
            throw new \RuntimeException('Too many secret manager key requests. Try again in '.RateLimiter::availableIn($rateLimitKey).' seconds.');
        }
        RateLimiter::hit($rateLimitKey, 60);

        try {
            $keys = array_keys($link->fetchSecrets());
        } catch (\Throwable) {
            throw new \RuntimeException('Unable to fetch secret manager keys.');
        }
        sort($keys);
        Cache::put($cacheKey, $keys, self::SECRET_MANAGER_KEYS_CACHE_SECONDS);

        return $keys;
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
