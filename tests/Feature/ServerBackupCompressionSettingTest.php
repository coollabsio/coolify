<?php

use App\Livewire\Server\Advanced;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('defaults and saves the server backup compression CPU preset', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    expect($server->settings->backup_compression_cpu_percentage)->toBe(25);

    Livewire::test(Advanced::class, ['server_uuid' => $server->uuid])
        ->assertSet('backupCompressionCpuPercentage', 25)
        ->assertSee('Backup compression CPU')
        ->assertSee('Sets how many CPU threads can be used to compress volume backups')
        ->assertDontSee('gzip fallback')
        ->assertSee('Low (25%)')
        ->set('backupCompressionCpuPercentage', 75)
        ->call('instantSave')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($server->settings->fresh()->backup_compression_cpu_percentage)->toBe(75);
});

it('rejects unsupported server backup compression CPU percentages', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Advanced::class, ['server_uuid' => $server->uuid])
        ->set('backupCompressionCpuPercentage', 30)
        ->call('instantSave')
        ->assertHasErrors(['backupCompressionCpuPercentage']);

    expect($server->settings->fresh()->backup_compression_cpu_percentage)->toBe(25);
});
