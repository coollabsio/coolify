<?php

use App\Livewire\SettingsEmail;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\TransactionalEmailChannel;
use App\Notifications\TransactionalEmails\EmailChangeVerification;
use App\Services\ConfigurationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setupTransactionalEmailFromNameAdmin(): User
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'smtp_enabled' => true,
        'smtp_from_address' => 'admin@example.com',
        'smtp_from_name' => 'admin',
        'smtp_host' => 'coolify-mail',
        'smtp_port' => 1025,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    return $user;
}

test('saving transactional sender persists the configured from name', function () {
    $user = setupTransactionalEmailFromNameAdmin();

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsEmail::class)
        ->set('smtpFromName', 'Coolify')
        ->set('smtpFromAddress', 'admin@example.com')
        ->call('submit')
        ->assertHasNoErrors();

    Once::flush();

    expect(instanceSettings()->smtp_from_name)->toBe('Coolify')
        ->and(instanceSettings()->smtp_from_address)->toBe('admin@example.com');
});

test('sending a test email persists the current from name before delivery', function () {
    $user = setupTransactionalEmailFromNameAdmin();

    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Notification::fake();

    Livewire::test(SettingsEmail::class)
        ->set('smtpFromName', 'Coolify')
        ->set('smtpFromAddress', 'admin@example.com')
        ->set('testEmailAddress', $user->email)
        ->call('sendTestEmail')
        ->assertHasNoErrors();

    Once::flush();

    expect(instanceSettings()->smtp_from_name)->toBe('Coolify');
});

test('transactional emails send with the configured from name instead of the address local part', function () {
    setupTransactionalEmailFromNameAdmin();

    InstanceSettings::findOrFail(0)->update([
        'smtp_from_name' => 'Coolify',
        'smtp_from_address' => 'admin@example.com',
    ]);
    Once::flush();

    config([
        'mail.default' => 'array',
        'mail.from.address' => 'hello@example.com',
        'mail.from.name' => 'Example',
    ]);
    Mail::purge();
    Mail::mailer();

    $this->mock(ConfigurationRepository::class, function ($mock) {
        $mock->shouldReceive('updateMailConfig')->andReturnUsing(function ($settings) {
            config([
                'mail.from.address' => $settings->smtp_from_address,
                'mail.from.name' => $settings->smtp_from_name,
            ]);
        });
    });

    $user = User::factory()->create(['email' => 'recipient@example.com']);
    $notification = new EmailChangeVerification(
        $user,
        '123456',
        'new@example.com',
        now()->addMinutes(10),
    );

    $channel = new TransactionalEmailChannel;
    $channel->send($user, $notification);

    $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();

    expect($messages)->not->toBeEmpty();

    $from = $messages->first()->getOriginalMessage()->getFrom()[0];

    expect($from->getAddress())->toBe('admin@example.com')
        ->and($from->getName())->toBe('Coolify')
        ->and($from->getName())->not->toBe('admin');
});
