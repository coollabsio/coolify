<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedBrowserResourceStack();
    $this->token = User::query()->where('email', 'test@example.com')->sole()
        ->createToken('ci-token', ['read'], null, 0)->accessToken;
});

it('edits a token from the token list and saves the changes', function () {
    loginAndSkipBoarding();

    $page = visit('/security/api-tokens')
        ->assertSee('New API token')
        ->click('button:visible:has-text("Edit")')
        ->assertSee('Edit API token')
        ->assertSee('Keep current (Never)')
        ->assertValue('description', 'ci-token')
        ->fill('description', 'deploy-token')
        ->click('button:visible:has-text("Save changes")')
        ->wait(1)
        ->assertSee('New API token')
        ->assertSee('deploy-token');

    expect(PersonalAccessToken::query()->sole()->name)->toBe('deploy-token');
    $page->screenshot(filename: 'api-token-edited');
});

it('cancels editing without saving', function () {
    loginAndSkipBoarding();

    $page = visit('/security/api-tokens')
        ->click('button:visible:has-text("Edit")')
        ->fill('description', 'changed')
        ->click('button:visible:has-text("Cancel")')
        ->wait(1)
        ->assertSee('New API token')
        ->assertValue('description', '');

    expect($this->token->fresh()->name)->toBe('ci-token');
    $page->screenshot(filename: 'api-token-edit-cancelled');
});

it('pauses and resumes a token from the token list', function () {
    loginAndSkipBoarding();

    $page = visit('/security/api-tokens')
        ->click('button:visible:has-text("Pause")')
        ->assertSee('API token paused.')
        ->assertSee('Paused')
        ->assertSee('Resume');

    expect($this->token->fresh()->isPaused())->toBeTrue();
    $page->screenshot(filename: 'api-token-paused');

    $page->click('button:visible:has-text("Resume")')
        ->assertSee('API token resumed.');

    expect($this->token->fresh()->isPaused())->toBeFalse();
});
