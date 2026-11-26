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
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.test']);

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $uuid = $this->application->uuid;
    $container = fn (string $name, ?int $pullRequestId) => json_encode([
        'ID' => $name,
        'Names' => $name,
        'State' => 'running',
        'Labels' => $pullRequestId === null
            ? "coolify.applicationUuid={$uuid},coolify.managed=true"
            : "coolify.applicationUuid={$uuid},coolify.pullRequestId={$pullRequestId},coolify.managed=true",
    ]);
    $output = implode(PHP_EOL, [
        $container("{$uuid}-base", 0),
        $container("{$uuid}-legacy", null),
        $container("{$uuid}-pr-1", 1),
        $container("{$uuid}-pr-12", 12),
        $container("{$uuid}-pr-13", 13),
        $container("{$uuid}-pr-100", 100),
    ]);

    Process::fake(fn () => Process::result(output: $output));
});

function previewContainerStatusNames(Server $server, Application $application, ?int $pullRequestId, bool $includePullrequests = false): array
{
    return getCurrentApplicationContainerStatus($server->fresh(), $application, $pullRequestId, $includePullrequests)
        ->pluck('Names')
        ->values()
        ->all();
}

test('a preview lookup returns only the containers of that exact pull request', function () {
    $uuid = $this->application->uuid;

    expect(previewContainerStatusNames($this->server, $this->application, 1))->toBe(["{$uuid}-pr-1"])
        ->and(previewContainerStatusNames($this->server, $this->application, 12))->toBe(["{$uuid}-pr-12"])
        ->and(previewContainerStatusNames($this->server, $this->application, 10))->toBe([]);
});

test('a base lookup still returns only base containers, or every container when previews are included', function () {
    $uuid = $this->application->uuid;

    expect(previewContainerStatusNames($this->server, $this->application, 0))->toBe(["{$uuid}-base", "{$uuid}-legacy"])
        ->and(previewContainerStatusNames($this->server, $this->application, null))->toBe(["{$uuid}-base", "{$uuid}-legacy"])
        ->and(previewContainerStatusNames($this->server, $this->application, 0, true))->toHaveCount(6);
});
