<?php

use App\Models\Project;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the first project with lima environments', function () {
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
        ->and($project->environments()->pluck('uuid', 'name')->all())->toBe([
            'ubuntu24' => 'ubuntu24',
            'ubuntu26' => 'ubuntu26',
        ]);
});
