<?php

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = Team::factory()->create();
    $user->teams()->attach($this->team);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

it('strips dangerous HTML from validation_logs via mutator', function () {
    $xssPayload = '<img src=x onerror=alert(document.domain)>';
    $this->server->update(['validation_logs' => $xssPayload]);
    $this->server->refresh();

    expect($this->server->validation_logs)->not->toContain('<img')
        ->and($this->server->validation_logs)->not->toContain('onerror');
});

it('strips script tags from validation_logs', function () {
    $xssPayload = '<script>alert("xss")</script>';
    $this->server->update(['validation_logs' => $xssPayload]);
    $this->server->refresh();

    expect($this->server->validation_logs)->not->toContain('<script');
});

it('preserves allowed HTML in validation_logs', function () {
    $allowedHtml = 'Server is not reachable.<br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs">documentation</a> for further help.<br><br><div class="text-error">Error: Connection refused</div>';
    $this->server->update(['validation_logs' => $allowedHtml]);
    $this->server->refresh();

    expect($this->server->validation_logs)->toContain('<a')
        ->and($this->server->validation_logs)->toContain('<br')
        ->and($this->server->validation_logs)->toContain('<div')
        ->and($this->server->validation_logs)->toContain('Connection refused');
});

it('allows null validation_logs', function () {
    $this->server->update(['validation_logs' => null]);
    $this->server->refresh();

    expect($this->server->validation_logs)->toBeNull();
});

it('sanitizes XSS embedded within valid error HTML', function () {
    $maliciousError = 'Server is not reachable.<br><div class="text-error">Error: <img src=x onerror=alert(document.cookie)></div>';
    $this->server->update(['validation_logs' => $maliciousError]);
    $this->server->refresh();

    expect($this->server->validation_logs)->toContain('<div')
        ->and($this->server->validation_logs)->toContain('Error:')
        ->and($this->server->validation_logs)->not->toContain('onerror')
        ->and($this->server->validation_logs)->not->toContain('<img');
});

it('sanitizes event handler attributes in validation_logs', function () {
    $payload = '<div onmouseover="alert(1)" class="text-error">Error</div>';
    $this->server->update(['validation_logs' => $payload]);
    $this->server->refresh();

    expect($this->server->validation_logs)->toContain('<div')
        ->and($this->server->validation_logs)->not->toContain('onmouseover');
});
