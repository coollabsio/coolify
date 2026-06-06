<?php

use App\Services\Docker\DockerNetworkInspector;

it('normalizes a docker network with full ipam data', function () {
    $normalized = (new DockerNetworkInspector)->normalize([[
        'Name' => 'bridge',
        'Id' => 'abc123',
        'Driver' => 'bridge',
        'Scope' => 'local',
        'EnableIPv6' => true,
        'Internal' => true,
        'Attachable' => false,
        'IPAM' => [
            'Config' => [[
                'Subnet' => '172.18.0.0/16',
                'Gateway' => '172.18.0.1',
                'IPRange' => '172.18.5.0/24',
                'AuxiliaryAddresses' => ['db' => '172.18.0.10'],
            ]],
        ],
        'Labels' => ['com.coolify.test' => 'true'],
        'Options' => ['encrypted' => 'true'],
        'Containers' => ['container-id' => ['Name' => 'api']],
    ]]);

    expect($normalized)
        ->docker_id->toBe('abc123')
        ->docker_network_name->toBe('bridge')
        ->driver->toBe('bridge')
        ->scope->toBe('local')
        ->subnet->toBe('172.18.0.0/16')
        ->gateway->toBe('172.18.0.1')
        ->ip_range->toBe('172.18.5.0/24')
        ->aux_addresses->toBe(['db' => '172.18.0.10'])
        ->internal->toBeTrue()
        ->attachable->toBeFalse()
        ->enable_ipv6->toBeTrue()
        ->labels->toBe(['com.coolify.test' => 'true'])
        ->options->toBe(['encrypted' => 'true'])
        ->containers->toBe(['container-id' => ['Name' => 'api']])
        ->raw->toHaveKey('Name');
});

it('normalizes missing inspect fields conservatively', function () {
    $normalized = (new DockerNetworkInspector)->normalize([
        'Name' => 'empty-network',
        'Labels' => null,
        'Options' => null,
        'Containers' => null,
    ]);

    expect($normalized)
        ->docker_network_name->toBe('empty-network')
        ->driver->toBe('unknown')
        ->scope->toBe('unknown')
        ->subnet->toBeNull()
        ->gateway->toBeNull()
        ->ip_range->toBeNull()
        ->aux_addresses->toBe([])
        ->internal->toBeFalse()
        ->attachable->toBeTrue()
        ->enable_ipv6->toBeFalse()
        ->labels->toBe([])
        ->options->toBe([])
        ->containers->toBe([])
        ->raw->toHaveKey('Name');
});
