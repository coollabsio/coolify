<?php

namespace App\Http\Middleware;

use App\Models\InstanceSettings;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Spatie\Url\Url;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $trustedHosts = [];
        // Trust the configured FQDN from InstanceSettings
        try {
            $settings = InstanceSettings::get();
            if ($settings && $settings->fqdn) {
                $url = Url::fromString($settings->fqdn);
                $host = $url->getHost();
                if ($host) {
                    $trustedHosts[] = $host;
                }
            }
        } catch (\Exception $e) {
            // If instance settings table doesn't exist yet (during installation),
            // fall back to APP_URL only
        }

        // Trust all subdomains of APP_URL as fallback
        $trustedHosts[] = $this->allSubdomainsOfApplicationUrl();

        return array_filter($trustedHosts);
    }
}
