<?php

use App\Livewire\Notifications\Discord as DiscordNotification;
use App\Livewire\Security\PrivateKey\Index as PrivateKeyIndex;
use App\Livewire\Team\Member\Index as TeamMemberIndex;
use App\Models\DiscordNotificationSettings;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    // Create a private key for the team (bypass model validation via DB insert)
    DB::table('private_keys')->insert([
        'uuid' => (string) Str::uuid(),
        'name' => 'Team SSH Key',
        'description' => 'Key for testing',
        'private_key' => 'test-key-content',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

// --- Private Key Index ---

test('admin sees add and cleanup buttons on private key page', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(PrivateKeyIndex::class)
        ->assertSee('+ Add')
        ->assertSee('Delete unused SSH Keys');
});

test('member does not see add or cleanup buttons on private key page', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(PrivateKeyIndex::class)
        ->assertDontSee('+ Add')
        ->assertDontSee('Delete unused SSH Keys');
});

test('member can view private key names on index page', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    // Member can see key names (view is allowed for same-team members)
    // but cannot see create/delete buttons (tested separately above)
    Livewire::test(PrivateKeyIndex::class)
        ->assertSee('Team SSH Key');
});

test('member cannot call cleanup unused keys', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(PrivateKeyIndex::class)
        ->call('cleanupUnusedKeys')
        ->assertDispatched('error');
});

// --- Team Member Index (Invitations) ---

test('admin sees invite section on team members page', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamMemberIndex::class)
        ->assertSee('Invite New Member');
});

test('member does not see invite section on team members page', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamMemberIndex::class)
        ->assertDontSee('Invite New Member');
});

// --- Notification Settings (Discord as representative) ---

test('member cannot send test notification on discord', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $settings = DiscordNotificationSettings::where('team_id', $this->team->id)->first();
    $settings->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/test',
    ]);

    Livewire::test(DiscordNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('error');
});
