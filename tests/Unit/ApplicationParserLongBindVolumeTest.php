<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

function parseLongBindApplicationCompose(string $compose): array
{
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::create([
        'name' => 'Long Bind Parser Team',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $project = Project::create([
        'name' => 'Long Bind Parser Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
    ]);

    $application->compose_parsing_version = '3';
    $application->save();

    return [
        'application' => $application,
        'parsed' => $application->fresh()->parse(),
    ];
}

function parsedVolume(array $parsed, string $service, int $index = 0): array|string
{
    return $parsed['services'][$service]['volumes'][$index];
}

it('preserves long-bind read_only as a boolean through the application parser', function () {
    ['parsed' => $parsed] = parseLongBindApplicationCompose(<<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: /synthetic/release
        target: /mnt/release
        read_only: true
YAML);

    expect(parsedVolume(convertToArray($parsed), 'app'))
        ->toMatchArray([
            'type' => 'bind',
            'source' => '/synthetic/release',
            'target' => '/mnt/release',
            'read_only' => true,
        ]);
});

it('preserves bind create_host_path false through the application parser', function () {
    ['parsed' => $parsed] = parseLongBindApplicationCompose(<<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: /synthetic/release
        target: /mnt/release
        bind:
          create_host_path: false
YAML);

    expect(parsedVolume(convertToArray($parsed), 'app'))
        ->toMatchArray([
            'bind' => ['create_host_path' => false],
        ]);
});

it('preserves the synthetic Trading-Bot-style long bind exactly', function () {
    ['application' => $application, 'parsed' => $parsed] = parseLongBindApplicationCompose(<<<'YAML'
services:
  bot:
    image: example/bot:latest
    volumes:
      - type: bind
        source: /synthetic/releases/historical-data/release-id
        target: /mnt/trading-bot/historical-data
        read_only: true
        bind:
          create_host_path: false
      - bot_sqlite:/data
volumes:
  bot_sqlite: {}
YAML);

    $result = convertToArray($parsed);

    expect(parsedVolume($result, 'bot'))
        ->toBe([
            'type' => 'bind',
            'source' => '/synthetic/releases/historical-data/release-id',
            'target' => '/mnt/trading-bot/historical-data',
            'read_only' => true,
            'bind' => ['create_host_path' => false],
        ])
        ->and(parsedVolume($result, 'bot', 1))->toBe($application->uuid.'_bot-sqlite:/data')
        ->and($result['networks'])->toHaveCount(1)
        ->and($result['services']['bot']['container_name'])->toContain('bot-')
        ->and($result['services']['bot'])->toHaveKey('environment');
});

it('keeps a writable long bind without an artificial read_only field', function () {
    ['parsed' => $parsed] = parseLongBindApplicationCompose(<<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: /synthetic/writable
        target: /mnt/writable
YAML);

    expect(parsedVolume(convertToArray($parsed), 'app'))
        ->not->toHaveKey('read_only');
});

it('keeps string form read-only volume behavior unchanged', function () {
    ['parsed' => $parsed] = parseLongBindApplicationCompose(<<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - /synthetic/config:/etc/config:ro
YAML);

    expect(parsedVolume(convertToArray($parsed), 'app'))->toBe('/synthetic/config:/etc/config:ro');
});

it('keeps named-volume behavior and namespacing unchanged', function () {
    ['application' => $application, 'parsed' => $parsed] = parseLongBindApplicationCompose(<<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - data:/data
volumes:
  data: {}
YAML);

    $result = convertToArray($parsed);
    $expectedName = $application->uuid.'_data';

    expect(parsedVolume($result, 'app'))->toBe($expectedName.':/data')
        ->and($result['volumes'][$expectedName])->toBe(['name' => $expectedName]);
});

it('normalizes a relative long bind source without losing sibling mapping fields', function () {
    ['application' => $application, 'parsed' => $parsed] = parseLongBindApplicationCompose(<<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: ./config
        target: /etc/config
        read_only: true
        bind:
          create_host_path: false
YAML);

    $volume = parsedVolume(convertToArray($parsed), 'app');

    expect($volume['source'])->not->toBe('./config')
        ->and($volume['source'])->toContain('/applications/'.$application->uuid)
        ->and($volume['target'])->toBe('/etc/config')
        ->and($volume['read_only'])->toBeTrue()
        ->and($volume['bind'])->toBe(['create_host_path' => false]);
});

it('preserves long-bind mappings and nested options through convertToArray and YAML serialization', function () {
    ['parsed' => $parsed] = parseLongBindApplicationCompose(<<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - type: bind
        source: /synthetic/release
        target: /mnt/release
        read_only: true
        bind:
          create_host_path: false
YAML);

    $serialized = Yaml::dump(convertToArray($parsed), 10, 2);
    $roundTripped = Yaml::parse($serialized);

    expect(parsedVolume($roundTripped, 'app'))->toBe([
        'type' => 'bind',
        'source' => '/synthetic/release',
        'target' => '/mnt/release',
        'read_only' => true,
        'bind' => ['create_host_path' => false],
    ]);
});
