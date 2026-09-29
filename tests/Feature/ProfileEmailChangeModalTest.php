<?php

use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Profile User', 'email' => 'old@example.com']);
    $this->user->forceFill([
        'pending_email' => 'new@example.com',
        'email_change_code' => '123456',
        'email_change_code_expires_at' => now()->addMinutes(10),
    ])->save();

    $this->actingAs($this->user);
});

it('closes the email change modal after a successful verification', function () {
    Livewire::test(ProfileIndex::class)
        ->set('email_verification_code', '123456')
        ->call('verifyEmailChange')
        ->assertDispatched('success', 'Email address updated successfully.')
        ->assertDispatched('close-email-change-modal')
        ->assertSet('show_verification', false);

    expect($this->user->fresh()->email)->toBe('new@example.com');
});

it('keeps the email change modal open when the verification code is wrong', function () {
    Livewire::test(ProfileIndex::class)
        ->set('email_verification_code', '654321')
        ->call('verifyEmailChange')
        ->assertDispatched('error', 'Invalid or expired verification code.')
        ->assertNotDispatched('close-email-change-modal');

    expect($this->user->fresh()->email)->toBe('old@example.com');
});
