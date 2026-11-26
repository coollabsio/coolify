<?php

use App\Jobs\SendMessageToDiscordJob;
use App\Livewire\Notifications\Discord;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sentDiscordContent(Team $team, bool $critical = true): ?string
{
    Queue::fake();

    (new DiscordChannel)->send($team->fresh(), new Test(channel: 'discord', ping: $critical));

    $content = null;
    Queue::assertPushed(SendMessageToDiscordJob::class, function (SendMessageToDiscordJob $job) use (&$content) {
        $content = $job->message->toPayload()['content'] ?? null;

        return true;
    });

    return $content;
}

beforeEach(function () {
    InstanceSettings::query()->forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->team->discordNotificationSettings->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/123/abc',
    ]);
});

it('mentions @here when no mention text is set', function () {
    expect(sentDiscordContent($this->team))->toBe('@here');
});

it('sends the custom mention text on critical events', function () {
    $this->team->discordNotificationSettings->update(['discord_ping_text' => '  <@&123456789> deployment failed  ']);

    expect(sentDiscordContent($this->team))->toBe('<@&123456789> deployment failed');
});

it('sends no mention when mentions are disabled', function () {
    $this->team->discordNotificationSettings->update([
        'discord_ping_enabled' => false,
        'discord_ping_text' => '@everyone',
    ]);

    expect(sentDiscordContent($this->team))->toBeNull();
});

it('sends no mention for non-critical events', function () {
    $this->team->discordNotificationSettings->update(['discord_ping_text' => '@everyone']);

    expect(sentDiscordContent($this->team, critical: false))->toBeNull();
});

it('saves the mention text from the settings page and hides it when mentions are off', function () {
    $user = User::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($user);

    Livewire::test(Discord::class)
        ->assertSee('Mention text')
        ->set('discordPingText', '<@123456789>')
        ->call('submit')
        ->assertHasNoErrors()
        ->set('discordPingEnabled', false)
        ->call('instantSaveDiscordPingEnabled')
        ->assertDontSee('Mention text');

    expect($this->team->discordNotificationSettings->fresh())
        ->discord_ping_text->toBe('<@123456789>')
        ->discord_ping_enabled->toBeFalse();
});
