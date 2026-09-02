<?php

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('leaves the user id sequence ready for new development users', function () {
    $this->seed(UserSeeder::class);

    $user = User::factory()->create();

    expect(User::query()->orderBy('id')->pluck('id')->all())->toBe([0, 1, 2, 3])
        ->and($user->id)->toBe(3);
});
