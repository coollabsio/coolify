<?php

namespace App\Actions\IntegrationToken;

use App\Models\IntegrationToken;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\IntegrationTokenValidator;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Creates an integration token after the provider accepted it. A Cloudflare token also gets its zones in the same
 * transaction, so a token without zone access is never stored. The security page and the API use this action.
 */
class CreateIntegrationToken
{
    use AsAction;

    public function __construct(
        private IntegrationTokenValidator $validator,
        private CloudflareDnsProvider $cloudflare,
    ) {}

    /**
     * Validation rules for the token fields. `automatic_dns` and `proxied_dns` are validated by the caller.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $provider): array
    {
        $rules = [
            'provider' => ['required', 'in:'.implode(',', array_keys(IntegrationToken::PROVIDER_NAMES))],
            'name' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
            'capabilities' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && in_array('tunnel', $value, true) && ! in_array('dns', $value, true)) {
                    $fail('Cloudflare Tunnel also needs the DNS capability.');
                }
            }],
            'capabilities.*' => ['required', 'in:'.implode(',', IntegrationToken::allowedCapabilities($provider))],
        ];

        if ($provider === 'doppler') {
            $rules['token'][] = 'regex:/^dp\.(st|sa)\./';
        } elseif ($provider === 'infisical') {
            $rules['metadata.base_url'] = ['required', 'url:http,https'];
            $rules['metadata.client_id'] = ['required', 'string'];
            $rules['metadata.timeout'] = ['nullable', 'integer', 'min:1', 'max:300'];
        } elseif ($provider === 'vault') {
            $rules['metadata.base_url'] = ['required', 'url:http,https'];
            $rules['metadata.namespace'] = ['nullable', 'string'];
            $rules['metadata.timeout'] = ['nullable', 'integer', 'min:1', 'max:300'];
        }

        return $rules;
    }

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $metadata  Provider settings (base URL, client ID, ...); empty values are dropped.
     *
     * @throws RuntimeException when the provider rejects the token or Cloudflare zones cannot be read
     */
    public function handle(
        int $teamId,
        string $provider,
        string $name,
        string $token,
        array $capabilities,
        array $metadata = [],
        bool $automaticDns = true,
        bool $proxiedDns = false,
    ): IntegrationToken {
        $metadata = array_filter($metadata, fn ($value) => filled($value));
        if ($provider === 'cloudflare' && ! $automaticDns) {
            $metadata['automatic_dns'] = false;
        }
        if ($provider === 'cloudflare' && $proxiedDns) {
            $metadata['proxied_dns'] = true;
        }

        if (! $this->validator->validate($provider, $token, $capabilities, $metadata)) {
            throw new RuntimeException($this->validator->errorMessage($provider));
        }

        return DB::transaction(function () use ($teamId, $provider, $name, $token, $capabilities, $metadata): IntegrationToken {
            $integrationToken = IntegrationToken::query()->create([
                'team_id' => $teamId, 'provider' => $provider, 'name' => $name, 'token' => $token,
                'capabilities' => $capabilities, 'metadata' => $metadata ?: null,
            ]);
            if ($provider === 'cloudflare') {
                $this->cloudflare->syncZones($integrationToken);
            }

            return $integrationToken;
        });
    }
}
