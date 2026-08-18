<?php

use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Livewire\Server\Index as ServerIndex;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();

    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
        'ip' => '203.0.113.24',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('server index masks the IP address instead of rendering it', function () {
    $html = Livewire::test(ServerIndex::class)->html();

    expect($html)
        ->toContain(str_repeat('•', 10))
        ->toContain("isIpRevealed(server.uuid) ? (server.ip || '-') : ipMask")
        ->not->toContain('x-text="server.ip"');
});

test('server index offers a reveal toggle and a copy action per server', function () {
    $html = Livewire::test(ServerIndex::class)->html();

    expect($html)
        ->toContain('toggleIp(server.uuid)')
        ->toContain('window.copyToClipboard(server.ip)')
        ->toContain('Copy IP address');
});

test('server index starts with every IP address masked and never persists a reveal', function () {
    $html = Livewire::test(ServerIndex::class)->html();

    expect($html)
        ->toContain('revealedIps: []')
        ->not->toContain('coolify-servers-reveal-ip');
});

test('server index does not expose IP addresses of other teams', function () {
    $otherTeam = Team::factory()->create();

    $otherKeyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Other Key',
        'private_key' => 'other-key',
        'team_id' => $otherTeam->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Server::factory()->create([
        'team_id' => $otherTeam->id,
        'private_key_id' => $otherKeyId,
        'ip' => '198.51.100.77',
    ]);

    $html = Livewire::test(ServerIndex::class)->html();

    expect($html)
        ->toContain('203.0.113.24')
        ->not->toContain('198.51.100.77');
});
