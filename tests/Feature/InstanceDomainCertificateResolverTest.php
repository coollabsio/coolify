<?php

use App\Models\InstanceSettings;
use App\Models\Server;

function coolifyControlPlaneCertificateResolvers(?string $resolver): array
{
    $settings = new InstanceSettings;
    $settings->fqdn = 'https://infra.example.com';
    $settings->instance_domain_certificate_resolver = $resolver;

    $server = new class extends Server
    {
        public function buildCoolifyTraefikDynamicConfigurationForTest(InstanceSettings $settings): array
        {
            return $this->buildCoolifyTraefikDynamicConfiguration($settings);
        }
    };

    $routers = $server->buildCoolifyTraefikDynamicConfigurationForTest($settings)['http']['routers'];

    return [
        'coolify-https' => $routers['coolify-https']['tls']['certresolver'],
        'coolify-realtime-wss' => $routers['coolify-realtime-wss']['tls']['certresolver'],
        'coolify-terminal-wss' => $routers['coolify-terminal-wss']['tls']['certresolver'],
    ];
}

it('uses letsencrypt for Coolify control-plane HTTPS routers by default', function () {
    expect(coolifyControlPlaneCertificateResolvers(null))->toBe([
        'coolify-https' => 'letsencrypt',
        'coolify-realtime-wss' => 'letsencrypt',
        'coolify-terminal-wss' => 'letsencrypt',
    ]);
});

it('uses configured resolver for all Coolify control-plane HTTPS routers', function () {
    expect(coolifyControlPlaneCertificateResolvers('letsencrypt-http'))->toBe([
        'coolify-https' => 'letsencrypt-http',
        'coolify-realtime-wss' => 'letsencrypt-http',
        'coolify-terminal-wss' => 'letsencrypt-http',
    ]);
});

it('falls back to letsencrypt for unsafe Coolify control-plane resolver values', function () {
    expect(coolifyControlPlaneCertificateResolvers("letsencrypt-http\nmalicious: true"))->toBe([
        'coolify-https' => 'letsencrypt',
        'coolify-realtime-wss' => 'letsencrypt',
        'coolify-terminal-wss' => 'letsencrypt',
    ]);
});
