<?php

use App\Livewire\Project\Service\Index;
use App\Models\Server;

function makeServiceIndexTestServer(bool $isSwarm = false): Server
{
    $server = new Server;
    $server->setRelation('settings', (object) [
        'is_swarm_manager' => $isSwarm,
        'is_swarm_worker' => false,
    ]);

    return $server;
}

function invokeLoadContainerInfo(Index $component): void
{
    \Closure::bind(function () {
        $this->loadContainerInfo();
    }, $component, Index::class)();
}

function makeTestableServiceIndex(): Index
{
    return new class extends Index
    {
        public ?string $testContainerName = null;

        public ?Server $testServer = null;

        public array|string $testContainerStatus = 'exited';

        public bool $fetchCalled = false;

        protected function containerInfoServer(): ?Server
        {
            return $this->testServer;
        }

        protected function containerInfoIdentifier(): ?string
        {
            return $this->testContainerName;
        }

        protected function fetchContainerStatus(Server $server, string $containerName, bool $allData = false): array|string
        {
            $this->fetchCalled = true;

            return $this->testContainerStatus;
        }
    };
}

test('service database proxy timeout requires a minimum of one second', function () {
    $component = new Index;
    $rules = (fn (): array => $this->rules)->call($component);

    expect($rules['publicPortTimeout'])
        ->toContain('min:1');
});

test('service index container info rejects invalid container identifiers before remote execution', function () {
    $component = makeTestableServiceIndex();
    $component->testContainerName = 'bad;name';
    $component->testServer = makeServiceIndexTestServer();

    invokeLoadContainerInfo($component);

    expect($component->fetchCalled)->toBeFalse()
        ->and($component->containerInfoError)->toContain('identifier is invalid')
        ->and($component->containerInfo['container_name'])->toBe('bad;name')
        ->and($component->containerInfo['networks'])->toBe([]);
});

test('service index container info reports swarm deployments as unavailable', function () {
    $component = makeTestableServiceIndex();
    $component->testContainerName = 'demo-service-123';
    $component->testServer = makeServiceIndexTestServer(isSwarm: true);
    $component->testContainerStatus = 'running';

    invokeLoadContainerInfo($component);

    expect($component->fetchCalled)->toBeTrue()
        ->and($component->containerInfoError)->toContain('Docker Swarm')
        ->and($component->containerInfo['container_name'])->toBe('demo-service-123')
        ->and($component->containerInfo['status'])->toBe('running');
});

test('service index container info surfaces unreachable container metadata errors', function () {
    $component = makeTestableServiceIndex();
    $component->testContainerName = 'demo-service-123';
    $component->testServer = makeServiceIndexTestServer();
    $component->testContainerStatus = 'exited';

    invokeLoadContainerInfo($component);

    expect($component->fetchCalled)->toBeTrue()
        ->and($component->containerInfoError)->toContain('server is unreachable')
        ->and($component->containerInfo['container_name'])->toBe('demo-service-123')
        ->and($component->containerInfo['status'])->toBe('exited');
});

test('service index container info formats inspect payloads through the presenter', function () {
    $component = makeTestableServiceIndex();
    $component->testContainerName = 'demo-service-123';
    $component->testServer = makeServiceIndexTestServer();
    $component->testContainerStatus = [
        'Id' => 'abc123',
        'Name' => '/demo-service-123',
        'Config' => [
            'Image' => 'ghcr.io/example/demo:latest',
            'Hostname' => 'demo-service-123',
        ],
        'Image' => 'sha256:demo',
        'Created' => '2026-05-13T04:00:00Z',
        'State' => [
            'Status' => 'running',
            'StartedAt' => '2026-05-13T04:05:00Z',
        ],
        'NetworkSettings' => [
            'Networks' => [
                'coolify' => [
                    'IPAddress' => '172.18.0.9',
                ],
            ],
        ],
    ];

    invokeLoadContainerInfo($component);

    expect($component->containerInfoError)->toBeNull()
        ->and($component->containerInfo['container_id'])->toBe('abc123')
        ->and($component->containerInfo['container_name'])->toBe('demo-service-123')
        ->and($component->containerInfo['status'])->toBe('running')
        ->and($component->containerInfo['networks'][0]['ipv4'])->toBe('172.18.0.9');
});
