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
     * - Terminal auth routes (called by realtime container)
     * - API routes (use token-based authentication, not host validation)
     * - Webhook endpoints (use cryptographic signature validation)
     */
    public function handle(Request $request, $next)
    {
        // Skip host validation for these routes
        if ($request->is(
            'terminal/auth',
            'terminal/auth/ips',
            'api/*',
            'webhooks/*'
        )) {
            return $next($request);
        }

        // Skip host validation if no FQDN is configured (initial setup)
        $fqdnHost = Cache::get('instance_settings_fqdn_host');
        if ($fqdnHost === '' || $fqdnHost === null) {
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

        // Trust the configured FQDN from InstanceSettings (cached to avoid DB query on every request)
        // Use empty string as sentinel value instead of null so negative results are cached
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

        // Convert sentinel value back to null for consumption
        $fqdnHost = $fqdnHost !== '' ? $fqdnHost : null;

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

        return array_filter($trustedHosts);
    }
}
