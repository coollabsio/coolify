<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RootUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->forceCreate(['id' => 0, 'is_registration_enabled' => true]);
    Team::factory()->create(['id' => 0, 'personal_team' => true]);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

    // The .env template of the installer has an empty ROOT_USERNAME line.
    putenv('ROOT_USERNAME=');
    putenv('ROOT_USER_EMAIL=root@coollabs.io');
    putenv('ROOT_USER_PASSWORD=Rw-Seeder-Test-Aa1!');
});

afterEach(function () {
    putenv('ROOT_USERNAME');
    putenv('ROOT_USER_EMAIL');
    putenv('ROOT_USER_PASSWORD');
});

it('creates the root user when ROOT_USERNAME is empty', function () {
    ob_start();
    (new RootUserSeeder)->run();
    ob_end_clean();

    expect(User::find(0))
        ->not->toBeNull()
        ->name->toBe('Root User')
        ->email->toBe('root@coollabs.io');
    expect(InstanceSettings::find(0)->is_registration_enabled)->toBeFalse();
});
