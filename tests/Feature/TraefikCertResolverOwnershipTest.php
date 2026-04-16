<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses public cert resolver when no master domain router is configured', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    expect(shouldUsePublicCertResolver($server))->toBeTrue();
});

it('uses public cert resolver only on the master domain router', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $otherServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    DB::table('server_settings')
        ->where('server_id', $masterServer->id)
        ->update(['is_master_domain_router_enabled' => true]);

    expect(shouldUsePublicCertResolver($masterServer))->toBeTrue();
    expect(shouldUsePublicCertResolver($otherServer))->toBeFalse();
});

it('passes public cert resolver ownership through both parser paths', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    expect(substr_count($parsersFile, 'use_public_cert_resolver: $usePublicCertResolver'))
        ->toBe(4);
});

it('generates application labels without an undefined public cert resolver variable', function () {
    $team = Team::factory()->create();

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
        'ports_exposes' => '3000',
    ]);

    $labels = generateLabelsApplication($application->fresh());

    expect($labels)
        ->toBeArray()
        ->not->toBeEmpty()
        ->and(collect($labels)->contains(fn (string $label) => str_contains($label, '.tls.certresolver=letsencrypt')))
        ->toBeTrue();
});
