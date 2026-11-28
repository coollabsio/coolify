<?php

namespace App\Services;

use App\Enums\LoadBalancerStrategy;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Server;

class ApplicationLoadBalancer
{
    private const TRAEFIK_STICKY_MAX_AGE_VERSION = '3.0';

    public function __construct(private Application $application) {}

    public function unavailableReason(): ?string
    {
        if ($this->application->build_pack === 'dockercompose') {
            return 'Set the load balancer of a Compose service with Traefik or Caddy labels in the Compose file.';
        }
        if ($this->application->replicasUnavailableReason() !== null) {
            return 'The load balancer shares the requests between replicas, and this application cannot use replicas.';
        }
        if (! in_array($this->server()?->proxyType(), [ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value], true)) {
            return 'The load balancer needs Traefik or Caddy as the proxy of the server.';
        }
        if ($this->application->settings?->is_container_label_readonly_enabled === false) {
            return 'Container labels are managed manually for this application. Switch label management back to Coolify, or add the load balancer labels yourself.';
        }

        return null;
    }

    public function strategyUnavailableReason(LoadBalancerStrategy $strategy): ?string
    {
        $server = $this->server();
        if ($server?->proxyType() === ProxyTypes::CADDY->value) {
            return $strategy->caddyPolicy() === null ? 'Caddy cannot pick the fastest replica.' : null;
        }
        if ($server?->proxyType() !== ProxyTypes::TRAEFIK->value || ($minimumVersion = $strategy->minimumTraefikVersion()) === null) {
            return null;
        }
        $version = $this->traefikVersion();
        if ($version === null) {
            return "Needs Traefik {$minimumVersion} or newer. Coolify does not know the Traefik version of this server yet.";
        }
        if (version_compare($version, $minimumVersion, '<')) {
            return "Needs Traefik {$minimumVersion} or newer. This server runs Traefik {$version}.";
        }

        return null;
    }

    public function effectiveStrategy(): LoadBalancerStrategy
    {
        $strategy = $this->application->load_balancer_strategy;

        return $this->strategyUnavailableReason($strategy) === null ? $strategy : LoadBalancerStrategy::RoundRobin;
    }

    public function fallbackNotice(): ?string
    {
        $strategy = $this->application->load_balancer_strategy;
        if ($this->unavailableReason() !== null || ($reason = $this->strategyUnavailableReason($strategy)) === null) {
            return null;
        }

        return "Balancing the replicas with round robin instead of {$strategy->label()}. {$reason}";
    }

    public function usesStickySessions(): bool
    {
        return $this->application->is_sticky_session_enabled && $this->effectiveStrategy() !== LoadBalancerStrategy::ClientIp;
    }

    public function supportsStickySessionMaxAge(): bool
    {
        $server = $this->server();

        return match ($server?->proxyType()) {
            ProxyTypes::TRAEFIK->value => ($version = $this->traefikVersion()) !== null && version_compare($version, self::TRAEFIK_STICKY_MAX_AGE_VERSION, '>='),
            ProxyTypes::CADDY->value => $server->caddySupportsStickyCookieMaxAge(),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public function traefikOptions(): array
    {
        if (! $this->configuresProxy(ProxyTypes::TRAEFIK)) {
            return [];
        }
        $options = ['strategy' => $this->effectiveStrategy()->traefikStrategy()];
        if ($this->usesStickySessions()) {
            $options = [
                ...$options,
                'sticky.cookie' => 'true',
                'sticky.cookie.name' => $this->application->sticky_session_cookie_name,
                'sticky.cookie.httponly' => 'true',
                'sticky.cookie.maxage' => $this->stickySessionMaxAge(),
            ];
        }

        return array_filter($options);
    }

    /**
     * @return array<string, string>
     */
    public function caddyOptions(): array
    {
        if (! $this->configuresProxy(ProxyTypes::CADDY)) {
            return [];
        }
        $policy = (string) $this->effectiveStrategy()->caddyPolicy();
        if (! $this->usesStickySessions()) {
            return ['lb_policy' => $policy];
        }
        $maxAge = $this->stickySessionMaxAge();

        return array_filter([
            'lb_policy' => "cookie {$this->application->sticky_session_cookie_name}",
            'lb_policy.fallback' => $policy,
            'lb_policy.max_age' => $maxAge ? "{$maxAge}s" : null,
        ]);
    }

    private function stickySessionMaxAge(): ?string
    {
        return $this->application->sticky_session_max_age && $this->supportsStickySessionMaxAge()
            ? (string) $this->application->sticky_session_max_age
            : null;
    }

    private function configuresProxy(ProxyTypes $proxy): bool
    {
        return $this->application->runsReplicas()
            && $this->unavailableReason() === null
            && $this->server()?->proxyType() === $proxy->value;
    }

    private function server(): ?Server
    {
        return $this->application->destination?->server;
    }

    private function traefikVersion(): ?string
    {
        $version = ltrim((string) $this->server()?->detected_traefik_version, 'v');

        return $version === '' ? null : $version;
    }
}
