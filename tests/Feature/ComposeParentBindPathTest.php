<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
});

function parentBindCompose(string $source, bool $longSyntax = false): string
{
    if ($longSyntax) {
        return <<<YAML
services:
  web:
    image: nginx:alpine
    volumes:
      - type: bind
        source: '{$source}'
        target: /data
YAML;
    }

    return <<<YAML
services:
  web:
    image: nginx:alpine
    volumes:
      - '{$source}:/data'
YAML;
}

function parentBindApplication(string $compose, ?string $parsingVersion = null): Application
{
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
    if ($parsingVersion !== null) {
        $application->update(['compose_parsing_version' => $parsingVersion]);
    }

    return $application->fresh();
}

function parentBindService(string $compose): Service
{
    return Service::factory()->create([
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'server_id' => test()->destination->server_id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ])->fresh();
}

/**
 * The host path of the first volume of the `web` service in a parsed Compose file.
 */
function parentBindParsedSource(array $compose): string
{
    $volume = $compose['services']['web']['volumes'][0];

    return is_array($volume) ? $volume['source'] : str($volume)->before(':/data')->value();
}

describe('applicationParser', function () {
    it('resolves parent directory segments of a new bind mount', function (string $source, string $expected, bool $longSyntax) {
        $application = parentBindApplication(parentBindCompose($source, $longSyntax));
        $directory = base_configuration_dir().'/applications/'.$application->uuid;
        $expected = str_replace('{dir}', $directory, $expected);

        $compose = applicationParser($application)->toArray();

        expect(parentBindParsedSource($compose))->toBe($expected)
            ->and($application->fileStorages()->where('mount_path', '/data')->value('fs_path'))->toBe($expected);
    })->with([
        'parent directory' => ['../outside', base_configuration_dir().'/applications/outside', false],
        'parent directory, long syntax' => ['../outside', base_configuration_dir().'/applications/outside', true],
        'dot segment in the middle' => ['./a/../b', '{dir}/b', false],
        'two parent directories' => ['../../outside/', base_configuration_dir().'/outside', false],
        'current directory stays unchanged' => ['./data', '{dir}/data', false],
        'tilde stays unchanged' => ['~/data', '{dir}/data', false],
    ]);

    it('rejects a bind mount that resolves above the root directory', function () {
        $application = parentBindApplication(parentBindCompose('../../../../../../../outside'));

        expect(fn () => applicationParser($application))
            ->toThrow(Exception::class, 'resolves to a path above /');
    });

    it('keeps the old path of an existing bind mount', function () {
        $application = parentBindApplication(parentBindCompose('../outside'));
        $oldPath = base_configuration_dir().'/applications/'.$application->uuid.'./outside';
        LocalFileVolume::create([
            'fs_path' => $oldPath,
            'mount_path' => '/data',
            'is_directory' => true,
            'resource_id' => $application->id,
            'resource_type' => $application->getMorphClass(),
        ]);

        $compose = applicationParser($application)->toArray();

        expect(parentBindParsedSource($compose))->toBe($oldPath)
            ->and($application->fileStorages()->where('mount_path', '/data')->pluck('fs_path')->all())->toBe([$oldPath]);
    });

    it('keeps the old path of an existing bind mount that was last written by a preview deployment', function () {
        $application = parentBindApplication(parentBindCompose('../outside'));
        $oldPath = base_configuration_dir().'/applications/'.$application->uuid.'./outside';
        LocalFileVolume::create([
            'fs_path' => $oldPath.'-pr-7',
            'mount_path' => '/data',
            'is_directory' => true,
            'resource_id' => $application->id,
            'resource_type' => $application->getMorphClass(),
        ]);

        $compose = applicationParser($application)->toArray();

        expect(parentBindParsedSource($compose))->toBe($oldPath);
    });

    it('uses the resolved path when the existing row has another path', function () {
        $application = parentBindApplication(parentBindCompose('../outside'));
        LocalFileVolume::create([
            'fs_path' => '/somewhere/else',
            'mount_path' => '/data',
            'is_directory' => true,
            'resource_id' => $application->id,
            'resource_type' => $application->getMorphClass(),
        ]);

        $compose = applicationParser($application)->toArray();

        expect(parentBindParsedSource($compose))->toBe(base_configuration_dir().'/applications/outside');
    });
});

describe('serviceParser', function () {
    it('resolves parent directory segments of a new bind mount', function (string $source, string $expected, bool $longSyntax) {
        $service = parentBindService(parentBindCompose($source, $longSyntax));
        $directory = base_configuration_dir().'/services/'.$service->uuid;
        $expected = str_replace('{dir}', $directory, $expected);

        $compose = serviceParser($service)->toArray();
        $web = ServiceApplication::query()->where('service_id', $service->id)->where('name', 'web')->firstOrFail();

        expect(parentBindParsedSource($compose))->toBe($expected)
            ->and($web->fileStorages()->where('mount_path', '/data')->value('fs_path'))->toBe($expected);
    })->with([
        'parent directory' => ['../outside', base_configuration_dir().'/services/outside', false],
        'parent directory, long syntax' => ['../outside', base_configuration_dir().'/services/outside', true],
        'dot segment in the middle' => ['./a/../b', '{dir}/b', false],
        'current directory stays unchanged' => ['./data', '{dir}/data', false],
        'tilde stays unchanged' => ['~/data', '{dir}/data', false],
    ]);

    it('rejects a bind mount that resolves above the root directory', function () {
        $service = parentBindService(parentBindCompose('../../../../../../../outside'));

        expect(fn () => serviceParser($service))
            ->toThrow(Exception::class, 'resolves to a path above /');
    });

    it('keeps the old path of an existing bind mount', function () {
        $service = parentBindService(parentBindCompose('../outside'));
        $web = ServiceApplication::create(['name' => 'web', 'service_id' => $service->id, 'image' => 'nginx:alpine']);
        $oldPath = base_configuration_dir().'/services/'.$service->uuid.'./outside';
        LocalFileVolume::create([
            'fs_path' => $oldPath,
            'mount_path' => '/data',
            'is_directory' => true,
            'resource_id' => $web->id,
            'resource_type' => $web->getMorphClass(),
        ]);

        $compose = serviceParser($service)->toArray();

        expect(parentBindParsedSource($compose))->toBe($oldPath)
            ->and($web->fileStorages()->where('mount_path', '/data')->pluck('fs_path')->all())->toBe([$oldPath]);
    });
});

describe('validation', function () {
    it('accepts parent directory bind sources', function (string $compose) {
        validateDockerComposeForInjection($compose);

        expect(parseDockerVolumeString('../outside:/data')['source']->value())->toBe('../outside');
    })->with([
        'short syntax' => [parentBindCompose('../outside')],
        'long syntax' => [parentBindCompose('../outside', true)],
    ]);

    it('accepts parent directory segments in content volumes', function () {
        $compose = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - type: bind
        source: ../outside/app.conf
        target: /app.conf
        content: hello
YAML;

        validateDockerComposeForInjection($compose);

        expect(true)->toBeTrue();
    });

    it('rejects shell metacharacters in a resolved parent directory path', function () {
        expect(fn () => replaceLocalSource(str('../out;side'), str('/data/coolify/services/abc')))
            ->toThrow(Exception::class);
    });
});

describe('legacy parser', function () {
    it('keeps the old path in legacy applications, which never stored their bind mounts', function () {
        $application = parentBindApplication(parentBindCompose('../outside'), '2');

        $compose = parseDockerComposeFile($application)->toArray();

        expect(parentBindParsedSource($compose))
            ->toBe(base_configuration_dir().'/applications/'.$application->uuid.'./outside');
    });
});

describe('storage', function () {
    it('treats both the old and the resolved path as a Compose mount of the administrator', function (string $fsPath) {
        $service = parentBindService(parentBindCompose('../outside'));
        $web = ServiceApplication::create(['name' => 'web', 'service_id' => $service->id, 'image' => 'nginx:alpine']);
        $volume = LocalFileVolume::create([
            'fs_path' => str_replace('{uuid}', $service->uuid, $fsPath),
            'mount_path' => '/data',
            'is_directory' => true,
            'resource_id' => $web->id,
            'resource_type' => $web->getMorphClass(),
        ]);

        $method = new ReflectionMethod(LocalFileVolume::class, 'isAdminControlledComposeMount');

        expect($method->invoke($volume->fresh()))->toBeTrue();
    })->with([
        'resolved path' => [base_configuration_dir().'/services/outside'],
        'old path' => [base_configuration_dir().'/services/{uuid}./outside'],
    ]);
});
