<?php

namespace App\Console\Commands;

use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Services\Auth\OauthIdentityIssuer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RekeyOauthIdentities extends Command
{
    protected $signature = 'oauth:rekey
        {provider : The OAuth provider, e.g. gitlab or azure}
        {--from= : The previous base URL, tenant, or OIDC issuer}
        {--to= : The new base URL, tenant, or OIDC issuer (defaults to the configured instance)}
        {--force : Rekey without asking for confirmation}';

    protected $description = 'Move linked OAuth identities to a new provider instance key after the same instance was renamed';

    public function handle(): int
    {
        $provider = (string) $this->argument('provider');
        $from = $this->instanceKey($provider, $this->option('from'));
        $to = filled($this->option('to'))
            ? $this->instanceKey($provider, $this->option('to'))
            : $this->configuredInstanceKey($provider);

        if ($from === null || $to === null) {
            $this->error('Could not determine the instance key. Pass --from and, if the provider is not configured, --to.');

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->info('The previous and new instance keys are the same.');

            return self::SUCCESS;
        }

        $identities = OauthIdentity::query()->where('provider', $provider)->where('issuer', $from);
        $count = $identities->count();
        if ($count === 0) {
            $this->info("No {$provider} identities use {$from}.");

            return self::SUCCESS;
        }

        $question = sprintf('Move %d %s %s from %s to %s?', $count, $provider, Str::plural('identity', $count), $from, $to);
        if (! $this->option('force') && ! $this->confirm($question)) {
            return self::FAILURE;
        }

        $conflicts = OauthIdentity::query()
            ->where('provider', $provider)
            ->where('issuer', $to)
            ->whereIn('provider_user_id', (clone $identities)->select('provider_user_id'))
            ->count();
        if ($conflicts > 0) {
            $this->error("{$conflicts} identities already exist under {$to}. Nothing was changed.");

            return self::FAILURE;
        }

        $identities->update(['issuer' => $to]);
        $this->info("Moved {$count} {$provider} ".Str::plural('identity', $count)." to {$to}.");

        return self::SUCCESS;
    }

    private function instanceKey(string $provider, mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if ($provider === 'oidc') {
            return $value;
        }

        return OauthIdentityIssuer::forProvider($provider, $value, $value);
    }

    private function configuredInstanceKey(string $provider): ?string
    {
        $oauthSetting = OauthSetting::query()->where('provider', $provider)->first();
        if ($oauthSetting === null || $provider === 'oidc') {
            return null;
        }

        return OauthIdentityIssuer::forSetting($oauthSetting);
    }
}
