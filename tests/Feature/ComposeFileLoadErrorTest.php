<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    Log::spy();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'git_repository' => 'https://github.com/coollabsio/private-repo',
        'git_branch' => 'main',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->gitError = "Cloning into 'checkout'...\nfatal: unable to access 'https://x-access-token:ghs_SECRET123@github.com/coollabsio/private-repo.git/': The requested URL returned error: 403 <b>denied</b>";
});

function fakeComposeLoadServer(?string $failingStep, string $errorOutput): void
{
    Process::fake(function ($process) use ($failingStep, $errorOutput) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if ($failingStep !== null && str_contains($command, $failingStep)) {
            return Process::result(errorOutput: $errorOutput, exitCode: 128);
        }

        return Process::result(output: '');
    });
}

it('shows and logs why the Compose file could not be read, without credentials', function () {
    fakeComposeLoadServer('git ls-tree', $this->gitError);

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(function (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('Failed to read the Docker Compose file from the repository.')
                ->toContain('The requested URL returned error: 403 &lt;b&gt;denied&lt;/b&gt;')
                ->toContain('https://***@github.com/')
                ->not->toContain('ghs_SECRET123')
                ->not->toContain('<b>');
        });

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Failed to read the Docker Compose file from the repository.'
        && $context['application_uuid'] === $this->application->uuid
        && str_contains($context['error'], 'The requested URL returned error: 403')
        && ! str_contains(json_encode($context), 'ghs_SECRET123'));
});

it('shows and logs why the Git source could not be read, without credentials', function () {
    fakeComposeLoadServer('ls-remote', $this->gitError);

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(function (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('Failed to read Git source. Please verify repository access and try again.')
                ->toContain('The requested URL returned error: 403')
                ->not->toContain('ghs_SECRET123');
        });

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Failed to read Git source.'
        && ! str_contains(json_encode($context), 'ghs_SECRET123'));
});

it('tells the user that a symlinked Compose file is not loaded', function () {
    $this->application->update(['docker_compose_raw' => "services:\n  web:\n    image: nginx\n"]);
    fakeComposeLoadServer('git ls-tree', 'The Docker Compose file is a symbolic link.');

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(RuntimeException::class, 'Docker Compose file at /docker-compose.yml (branch: main) is a symbolic link.');
    expect($this->application->fresh()->docker_compose_raw)->toBe("services:\n  web:\n    image: nginx\n");
});
