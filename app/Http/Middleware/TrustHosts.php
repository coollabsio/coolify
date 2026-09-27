<?php

namespace App\Http\Middleware;

use App\Models\InstanceSettings;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Url\Url;

class TrustHosts extends Middleware
{
    /**
     * Handle the incoming request.
     *
     * Skip host validation for certain routes:
     * - API routes (use token-based authentication, not host validation)
     * - Webhook endpoints (use cryptographic signature validation)
     */
    public function handle(Request $request, $next)
    {
        // Skip host validation for these routes
        if ($request->is(
            'api/*',
            'webhooks/*'
        )) {
            return $next($request);
        }

        // Skip host validation if no FQDN is configured (initial setup)
        if ($this->configuredFqdnHost() === null) {
            return $next($request);
        }

        // For all other routes, use parent's host validation
        return parent::handle($request, $next);
    }

    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $trustedHosts = [];

        $fqdnHost = $this->configuredFqdnHost();

        if ($fqdnHost) {
            $trustedHosts[] = $fqdnHost;
        }

        // Trust the APP_URL host itself (not just subdomains)
        $appUrl = config('app.url');
        if ($appUrl) {
            try {
                $appUrlHost = parse_url($appUrl, PHP_URL_HOST);
                if ($appUrlHost && ! in_array($appUrlHost, $trustedHosts, true)) {
                    $trustedHosts[] = $appUrlHost;
                }
            } catch (\Exception $e) {
                // Ignore parse errors
            }
        }

        // Trust all subdomains of APP_URL as fallback
        $trustedHosts[] = $this->allSubdomainsOfApplicationUrl();

        // Always trust loopback addresses so local access works even when FQDN is configured
        foreach (['localhost', '127.0.0.1', '[::1]'] as $localHost) {
            if (! in_array($localHost, $trustedHosts, true)) {
                $trustedHosts[] = $localHost;
            }
        }

        return array_filter($trustedHosts);
    }

    /**
     * Resolve the configured instance FQDN host, cached to avoid a DB query on every request.
     *
     * An empty string is cached as the "no FQDN" sentinel so negative results are cached too.
     */
    private function configuredFqdnHost(): ?string
    {
        $fqdnHost = Cache::remember('instance_settings_fqdn_host', 300, function () {
            try {
                $settings = InstanceSettings::get();
                if ($settings && $settings->fqdn) {
                    $url = Url::fromString($settings->fqdn);
                    $host = $url->getHost();

                    return $host ?: '';
                }
            } catch (\Exception $e) {
                // If instance settings table doesn't exist yet (during installation),
                // return empty string (sentinel) so this result is cached
            }

            return '';
        });

        return $fqdnHost !== '' ? $fqdnHost : null;
    }
}
