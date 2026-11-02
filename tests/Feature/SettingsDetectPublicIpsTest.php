<?php

use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);

    InstanceSettings::forceCreate(['id' => 0, 'public_ipv4' => '203.0.113.21', 'public_ipv6' => '2001:db8::21']);
    Once::flush();

    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->user = User::factory()->create();
    $this->rootTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->rootTeam]);

    Process::fake([
        'curl -4s --max-time 10 https://ifconfig.io' => "203.0.113.1\n",
        'curl -6s --max-time 10 https://ifconfig.io' => "2001:db8::1\n",
    ]);
});

it('saves the detected public IPs and shows them in the fields', function () {
    Livewire::test(SettingsIndex::class)
        ->call('detectPublicIps')
        ->assertSet('public_ipv4', '203.0.113.1')
        ->assertSet('public_ipv6', '2001:db8::1')
        ->assertDispatched('success');

    $settings = InstanceSettings::find(0);
    expect($settings->public_ipv4)->toBe('203.0.113.1')
        ->and($settings->public_ipv6)->toBe('2001:db8::1');
});

it('keeps the current value of an address family that cannot be detected', function () {
    Process::fake([
        'curl -4s --max-time 10 https://ifconfig.io' => "203.0.113.1\n",
        'curl -6s --max-time 10 https://ifconfig.io' => '',
    ]);

    Livewire::test(SettingsIndex::class)
        ->call('detectPublicIps')
        ->assertSet('public_ipv4', '203.0.113.1')
        ->assertSet('public_ipv6', '2001:db8::21');

    expect(InstanceSettings::find(0)->public_ipv6)->toBe('2001:db8::21');
});

it('shows an error when no address can be detected', function () {
    Process::fake([
        'curl -4s --max-time 10 https://ifconfig.io' => '',
        'curl -6s --max-time 10 https://ifconfig.io' => '',
    ]);

    Livewire::test(SettingsIndex::class)
        ->call('detectPublicIps')
        ->assertSet('public_ipv4', '203.0.113.21')
        ->assertSet('public_ipv6', '2001:db8::21')
        ->assertDispatched('error')
        ->assertNotDispatched('success');
});

it('does not let a user who is no longer an instance admin detect public IPs', function () {
    $component = Livewire::test(SettingsIndex::class);

    $this->rootTeam->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->actingAs($this->user->fresh());
    Cache::flush();
    Once::flush();

    $component->call('detectPublicIps')
        ->assertDispatched('error')
        ->assertSet('public_ipv4', '203.0.113.21');
    expect(InstanceSettings::find(0)->public_ipv4)->toBe('203.0.113.21');
    Process::assertNothingRan();
});
