<?php

use App\Models\Project;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the first project with only a production environment', function () {
    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ProjectSeeder::class,
    ]);

    $project = Project::query()
        ->where('uuid', 'project')
        ->first();

    expect($project)
        ->not->toBeNull()
        ->and($project->name)->toBe('My first project')
        ->and($project->environments)->toHaveCount(1)
        ->and($project->environments->first()->name)->toBe('production');
});
