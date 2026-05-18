<?php

use App\Models\Application;
use Database\Seeders\ApplicationSeeder;
use Database\Seeders\GithubAppSeeder;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\ServerSeeder;
use Database\Seeders\StandaloneDockerSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds a railpack nodejs fastify example alongside the existing nixpacks example', function () {
    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ServerSeeder::class,
        ProjectSeeder::class,
        StandaloneDockerSeeder::class,
        GithubAppSeeder::class,
        ApplicationSeeder::class,
    ]);

    $nixpacksExample = Application::where('uuid', 'nodejs')->first();
    $railpackExample = Application::where('uuid', 'railpack-nodejs')->first();

    expect($nixpacksExample)
        ->not->toBeNull()
        ->and($nixpacksExample->name)->toBe('NodeJS Fastify Example')
        ->and($nixpacksExample->build_pack)->toBe('nixpacks')
        ->and($nixpacksExample->base_directory)->toBe('/nodejs')
        ->and($nixpacksExample->ports_exposes)->toBe('3000');

    expect($railpackExample)
        ->not->toBeNull()
        ->and($railpackExample->name)->toBe('Railpack NodeJS Fastify Example')
        ->and($railpackExample->fqdn)->toBe('http://railpack-nodejs.127.0.0.1.sslip.io')
        ->and($railpackExample->repository_project_id)->toBe(603035348)
        ->and($railpackExample->git_repository)->toBe('coollabsio/coolify-examples')
        ->and($railpackExample->git_branch)->toBe('v4.x')
        ->and($railpackExample->base_directory)->toBe('/nodejs')
        ->and($railpackExample->build_pack)->toBe('railpack')
        ->and($railpackExample->ports_exposes)->toBe('3000')
        ->and($railpackExample->environment_id)->toBe(1)
        ->and($railpackExample->destination_id)->toBe(0)
        ->and($railpackExample->source_id)->toBe(1);
});
