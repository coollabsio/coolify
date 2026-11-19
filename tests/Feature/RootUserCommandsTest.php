<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fails with a clear message when the root user does not exist', function (string $command) {
    $this->artisan($command)
        ->expectsOutput('Root user not found. Create the root account in the browser first.')
        ->assertFailed();
})->with(['root:change-email', 'root:reset-password']);

it('changes the email of the root user', function () {
    User::factory()->create(['id' => 0, 'email' => 'old@example.com']);

    $this->artisan('root:change-email')
        ->expectsQuestion('Give me a new email for root user', ' New@Example.com ')
        ->assertSuccessful();

    expect(User::find(0)->email)->toBe('new@example.com');
});

it('does not change the email of the root user to an invalid or used email', function (string $email) {
    User::factory()->create(['id' => 0, 'email' => 'root@example.com']);
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('root:change-email')
        ->expectsQuestion('Give me a new email for root user', $email)
        ->assertFailed();

    expect(User::find(0)->email)->toBe('root@example.com');
})->with(['not-an-email', 'Taken@example.com']);
