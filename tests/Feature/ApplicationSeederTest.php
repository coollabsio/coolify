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

it('seeds the default applications without railpack examples', function () {
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

    expect($nixpacksExample)
        ->not->toBeNull()
        ->and($nixpacksExample->name)->toBe('NodeJS Fastify Example')
        ->and($nixpacksExample->build_pack)->toBe('nixpacks')
        ->and($nixpacksExample->base_directory)->toBe('/nodejs')
        ->and($nixpacksExample->ports_exposes)->toBe('3000');

    expect(Application::query()->where('build_pack', 'railpack')->exists())->toBeFalse();
    expect(Application::query()->whereIn('uuid', ['railpack-nodejs', 'railpack-static'])->exists())->toBeFalse();
});
