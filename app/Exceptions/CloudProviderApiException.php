<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * An error response from a cloud provider API (Hetzner, DigitalOcean, Vultr).
 *
 * @see https://docs.hetzner.cloud/reference/cloud#errors
 */
class CloudProviderApiException extends Exception
{
    public readonly ?string $errorCode;

    /** @var array<int, string> */
    public readonly array $invalidFields;

    /**
     * The error code and field names come from the provider, so only values that look like identifiers are kept.
     *
     * @param  array<int, mixed>  $invalidFields
     */
    public function __construct(
        public readonly string $provider,
        string $providerMessage,
        public readonly int $status,
        mixed $errorCode = null,
        array $invalidFields = [],
    ) {
        parent::__construct("{$provider} API error: {$providerMessage}", $status);

        $errorCode = is_string($errorCode) ? strtolower($errorCode) : null;
        $this->errorCode = $errorCode !== null && preg_match('/^[a-z_]{1,64}$/', $errorCode) ? $errorCode : null;
        $this->invalidFields = array_values(array_filter(
            $invalidFields,
            fn ($name) => is_string($name) && preg_match('/^[a-z0-9_.\[\]]{1,64}$/i', $name)
        ));
    }

    /**
     * A message that is safe to return to API clients. It is built only from the
     * HTTP status, the error code, and field names, never from the provider's free-text message.
     */
    public function safeMessage(): string
    {
        return match (true) {
            $this->errorCode === 'resource_limit_exceeded' => "Your {$this->provider} account has reached a resource limit, for example the server limit. Remove unused resources or ask {$this->provider} for a higher limit.",
            $this->errorCode === 'resource_unavailable' => 'The selected server type, image, or location is not available now. Select a different location or server type.',
            $this->errorCode === 'uniqueness_error' => "A resource with the same name already exists in your {$this->provider} account, for example a server or an SSH key. Use a different name.",
            $this->errorCode === 'maintenance', $this->status === 423, $this->status >= 500 => "{$this->provider} cannot process the request now. Try again later.",
            $this->status === 401 => "The {$this->provider} API token is invalid or expired. Update the cloud provider token in Coolify.",
            $this->status === 403 => "The {$this->provider} API token does not have permission for this request. Use a token with read and write access.",
            $this->status === 404 => "{$this->provider} could not find a requested resource, for example the location, server type, image, or SSH key.",
            $this->status === 409 => "{$this->provider} rejected the request because of a conflict with an existing resource.",
            $this->status === 422 => "{$this->provider} rejected the request as invalid. Check the selected values and your account limits.".($this->invalidFields !== [] ? ' Invalid fields: '.implode(', ', $this->invalidFields).'.' : ''),
            default => "{$this->provider} returned HTTP {$this->status}".($this->errorCode !== null ? " (error code: {$this->errorCode})" : '').'.',
        };
    }

    /**
     * The API response for a failed provider request: 502, because the upstream API rejected it.
     */
    public function toJsonResponse(string $failureMessage): JsonResponse
    {
        return response()->json([
            'message' => $failureMessage.' '.$this->safeMessage(),
            'provider_status' => $this->status,
            'provider_error_code' => $this->errorCode,
        ], 502);
    }
}
