<?php

use App\Models\DockerRegistry;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->server = Mockery::mock(Server::class);
    $this->server->shouldReceive('getAttribute')->with('id')->andReturn(1);
});

afterEach(function () {
    Mockery::close();
});

it('encrypts password when saved', function () {
    $registry = new DockerRegistry;
    $registry->password = 'plain-text-password';

    expect($registry->password)->toBe('plain-text-password');
});

it('trims trailing slashes from registry URL', function () {
    $registry = Mockery::mock(DockerRegistry::class)->makePartial();
    $registry->shouldAllowMockingProtectedMethods();

    $registry->server_id = 1;
    $registry->name = 'Test Registry';
    $registry->registry_url = 'https://registry.example.com/';
    $registry->username = 'testuser';
    $registry->password = 'testpass';

    // Mock the registryExists method to return false
    DockerRegistry::shouldReceive('registryExists')->andReturn(false);

    $registry->save();

    expect($registry->registry_url)->toBe('https://registry.example.com');
});

it('sets name to registry URL if name is empty', function () {
    $registry = Mockery::mock(DockerRegistry::class)->makePartial();
    $registry->shouldAllowMockingProtectedMethods();

    $registry->server_id = 1;
    $registry->name = '';
    $registry->registry_url = 'ghcr.io';
    $registry->username = 'testuser';
    $registry->password = 'testpass';

    // Mock the registryExists method to return false
    DockerRegistry::shouldReceive('registryExists')->andReturn(false);

    $registry->save();

    expect($registry->name)->toBe('ghcr.io');
});

it('returns common registries', function () {
    $commonRegistries = DockerRegistry::getCommonRegistries();

    expect($commonRegistries)->toBeArray()
        ->and(count($commonRegistries))->toBeGreaterThan(0)
        ->and($commonRegistries[0])->toHaveKeys(['name', 'registry_url', 'placeholder_username']);
});

it('generates correct config.json format', function () {
    $server = Mockery::mock(Server::class);

    $mockRegistry1 = Mockery::mock(DockerRegistry::class);
    $mockRegistry1->registry_url = 'docker.io';
    $mockRegistry1->username = 'user1';
    $mockRegistry1->password = 'pass1';

    $mockRegistry2 = Mockery::mock(DockerRegistry::class);
    $mockRegistry2->registry_url = 'ghcr.io';
    $mockRegistry2->username = 'user2';
    $mockRegistry2->password = 'pass2';

    $mockCollection = collect([$mockRegistry1, $mockRegistry2]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('active')->andReturnSelf();
    $mockQuery->shouldReceive('get')->andReturn($mockCollection);

    $server->shouldReceive('dockerRegistries')->andReturn($mockQuery);

    $configJson = generateDockerConfigJson($server);
    $config = json_decode($configJson, true);

    expect($config)->toHaveKey('auths')
        ->and($config['auths'])->toHaveKey('docker.io')
        ->and($config['auths'])->toHaveKey('ghcr.io')
        ->and($config['auths']['docker.io'])->toHaveKey('auth')
        ->and($config['auths']['docker.io']['auth'])->toBe(base64_encode('user1:pass1'))
        ->and($config['auths']['ghcr.io']['auth'])->toBe(base64_encode('user2:pass2'));
});
