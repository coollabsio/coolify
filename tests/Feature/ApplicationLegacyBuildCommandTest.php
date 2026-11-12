<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    // Saved before the command fields were validated, then converted from Nixpacks to Dockerfile.
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
        'install_command' => 'npm ci; npm run prepare',
        'build_command' => 'npm run build > build.log',
        'start_command' => 'node $ENTRY',
    ])->refresh();
});

test('saving keeps unchanged legacy commands that the current pattern rejects', function () {
    Livewire::test(General::class, ['application' => $this->application])
        ->set('description', 'New description')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertNotDispatched('error');

    $this->application->refresh();
    expect($this->application->description)->toBe('New description')
        ->and($this->application->install_command)->toBe('npm ci; npm run prepare')
        ->and($this->application->build_command)->toBe('npm run build > build.log')
        ->and($this->application->start_command)->toBe('node $ENTRY');
});

test('switching the build pack to Nixpacks works with unchanged legacy commands', function () {
    Livewire::test(General::class, ['application' => $this->application])
        ->set('buildPack', 'nixpacks')
        ->assertNotDispatched('error');

    expect($this->application->refresh()->build_pack)->toBe('nixpacks');
});

test('a changed command must still match the shell-safe pattern', function (string $property, string $column) {
    $original = $this->application->{$column};

    Livewire::test(General::class, ['application' => $this->application])
        ->set($property, 'npm run build; id')
        ->call('submit')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->application->refresh()->{$column})->toBe($original);
})->with([
    'install command' => ['installCommand', 'install_command'],
    'build command' => ['buildCommand', 'build_command'],
    'start command' => ['startCommand', 'start_command'],
]);

test('a legacy command reaches the builder as one argument and cannot run commands on the server', function (string $buildPack, string $method, string $payload) {
    $marker = sys_get_temp_dir().'/coolify-legacy-command-'.uniqid();
    $hostile = str_replace('MARKER', $marker, $payload);
    $this->application->forceFill([
        'build_pack' => $buildPack,
        'install_command' => $hostile,
        'build_command' => $hostile,
        'start_command' => $hostile,
    ])->saveQuietly();

    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    foreach (['application' => $this->application->refresh(), 'workdir' => '/artifacts/app', 'deployment_uuid' => 'helper', 'pull_request_id' => 0, 'env_railpack_args' => ''] as $property => $value) {
        (new ReflectionProperty($job, $property))->setValue($job, $value);
    }
    $command = (new ReflectionMethod($job, $method))->invoke($job);

    // The deployment wraps the command for the helper container and adds sudo on servers with a non-root user.
    $script = str_replace('docker exec', 'sudo docker exec', executeInDocker('helper', $command));

    $bin = sys_get_temp_dir().'/coolify-legacy-bin-'.uniqid();
    mkdir($bin);
    file_put_contents("$bin/sudo", "#!/bin/bash\nexec \"\$@\"\n");
    file_put_contents("$bin/docker", "#!/bin/bash\nshift 2\nexec \"\$@\"\n");
    file_put_contents("$bin/nixpacks", "#!/bin/bash\nprintf '%s\\0' \"\$@\" > $bin/args\n");
    copy("$bin/nixpacks", "$bin/railpack");
    array_map(fn (string $file) => chmod($file, 0755), glob("$bin/*"));

    $result = Process::env(['PATH' => "$bin:".getenv('PATH')])->input($script."\n")->run('bash -s');
    $arguments = explode("\0", rtrim(file_get_contents("$bin/args"), "\0"));

    expect($result->successful())->toBeTrue()
        ->and(file_exists($marker))->toBeFalse()
        // The sudo replacement also edits the text inside the value. This changes the value but does not run it.
        ->and(array_count_values($arguments)[str_replace('docker exec', 'sudo docker exec', $hostile)] ?? 0)->toBe($method === 'nixpacks_build_cmd' ? 3 : 2);
})->with([
    'nixpacks' => ['nixpacks', 'nixpacks_build_cmd'],
    'railpack' => ['railpack', 'railpack_prepare_command'],
])->with([
    'quote break-out' => ["x'; touch MARKER; echo '"],
    'newline' => ["npm run build\ntouch MARKER"],
    'command substitution' => ['$(touch MARKER)'],
    'backticks' => ['`touch MARKER`'],
    'sudo text inside the value' => ["docker exec x touch MARKER'; touch MARKER; '"],
]);
