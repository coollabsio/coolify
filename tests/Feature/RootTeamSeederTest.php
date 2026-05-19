<?php

use App\Models\Team;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\RootUserSeeder;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    foreach (['ROOT_USERNAME', 'ROOT_USER_EMAIL', 'ROOT_USER_PASSWORD'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

function coolifySetRootEnvironment(string $key, string $value): void
{
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

it('creates the root team before production system records need team id zero', function () {
    expect(Team::find(0))->toBeNull();

    $team = ProductionSeeder::ensureRootTeamExists();

    expect($team->id)->toBe(0)
        ->and($team->name)->toBe('Root Team')
        ->and($team->personal_team)->toBeTrue();
});

it('attaches the root user to an existing root team', function () {
    app()->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
    {
        public function verify($data): bool
        {
            return true;
        }
    });

    Team::forceCreate([
        'id' => 0,
        'name' => 'Root Team',
        'description' => 'The root team',
        'personal_team' => true,
        'show_boarding' => true,
    ]);
    coolifySetRootEnvironment('ROOT_USERNAME', 'Root User');
    coolifySetRootEnvironment('ROOT_USER_EMAIL', 'root@coolify.io');
    coolifySetRootEnvironment('ROOT_USER_PASSWORD', 'Str0ng!Kubernetes!Passphrase!2026');

    (new RootUserSeeder)->run();

    $rootUser = User::find(0);

    expect($rootUser)->not->toBeNull()
        ->and($rootUser->teams()->whereKey(0)->wherePivot('role', 'owner')->exists())->toBeTrue();
});
