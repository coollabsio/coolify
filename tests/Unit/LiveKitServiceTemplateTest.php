<?php

use Symfony\Component\Yaml\Yaml;

it('provides a LiveKit service with generated credentials and direct media ports', function () {
    $path = dirname(__DIR__, 2).'/templates/compose/livekit.yaml';
    $text = file_get_contents($path);
    $compose = Yaml::parse($text);
    $livekit = $compose['services']['livekit'];

    expect($text)->toContain('# port: 7880');
    expect($livekit['environment']['SERVICE_URL_LIVEKIT_7880'])->toBe('');
    expect($livekit['environment']['LIVEKIT_KEYS'])->toContain('SERVICE_USER_LIVEKIT', 'SERVICE_PASSWORD_64_LIVEKIT');
    expect($livekit['environment']['NODE_IP'])->toBe('${LIVEKIT_NODE_IP:-}');
    expect($livekit['ports'])->toBe(['7881:7881/tcp', '7882-7889:7882-7889/udp']);
    expect($livekit['environment']['LIVEKIT_CONFIG'])
        ->toContain('tcp_port: 7881', 'udp_port: 7882-7889', 'use_external_ip: ${LIVEKIT_USE_EXTERNAL_IP:-true}')
        ->not->toContain('port_range_start', 'port_range_end', 'turn:');
    expect($compose['services']['redis'])->not->toHaveKey('ports');
    expect($compose['services']['redis']['command'])->toContain('${SERVICE_PASSWORD_64_REDIS}');
    expect($livekit['depends_on']['redis']['condition'])->toBe('service_healthy');
});

it('provides the LiveKit icon at the catalog path', function () {
    expect(dirname(__DIR__, 2).'/public/svgs/livekit.png')->toBeFile();
});
