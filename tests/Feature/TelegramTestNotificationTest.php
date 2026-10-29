<?php

use App\Livewire\Notifications\Telegram as TelegramNotification;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->team->telegramNotificationSettings->update([
        'telegram_enabled' => true,
        'telegram_token' => 'bot-token',
        'telegram_chat_id' => '123456',
    ]);

    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);
    Queue::fake();
});

it('shows the error from Telegram when the test notification is rejected', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

    Livewire::test(TelegramNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('error', 'Telegram rejected the test notification.', 'Bad Request: chat not found')
        ->assertNotDispatched('success');

    Queue::assertNothingPushed();
});

it('sends the test notification synchronously and reports success', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    Livewire::test(TelegramNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('success', 'Test notification sent.');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/botbot-token/sendMessage'
        && $request['chat_id'] === '123456');
    Queue::assertNothingPushed();
});

it('ignores whitespace around the stored bot token and chat ID', function () {
    $this->team->telegramNotificationSettings->update([
        'telegram_token' => " bot-token \n",
        'telegram_chat_id' => ' 123456 ',
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    Livewire::test(TelegramNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('success', 'Test notification sent.');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/botbot-token/sendMessage'
        && $request['chat_id'] === '123456');
});

it('tells the user to check the bot token when Telegram does not find the bot', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 404, 'description' => 'Not Found'], 404)]);

    Livewire::test(TelegramNotification::class)
        ->call('sendTestNotification')
        ->assertDispatched('error', 'Telegram rejected the test notification.', 'The Bot API token is not valid. Copy it again from @BotFather.')
        ->assertNotDispatched('success');
});
