<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\EmailChannel;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mailer\Exception\TransportException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.coolify.self_hosted' => true]);

    $this->team = Team::factory()->create();
    $user = User::factory()->create(['email' => 'member@example.com']);
    $this->team->members()->attach($user->id, ['role' => 'owner']);

    $this->team->emailNotificationSettings->update([
        'use_instance_email_settings' => false,
        'resend_enabled' => false,
        'smtp_enabled' => true,
        'smtp_from_name' => 'Coolify',
        'smtp_from_address' => 'sender@example.com',
        'smtp_port' => 25,
        'smtp_timeout' => 1,
    ]);

    $this->smtpNotification = new class extends Notification
    {
        public string $emails = 'member@example.com';

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Deployment')->line('Deployed');
        }
    };
});

afterEach(function () {
    SafeWebhookUrl::resolveHostsUsing(null);
});

it('counts a blocked SMTP host as a delivery failure', function () {
    $this->team->emailNotificationSettings->update(['smtp_host' => '127.0.0.1']);

    (new EmailChannel)->send($this->team->fresh(), $this->smtpNotification);

    expect($this->team->emailNotificationSettings()->first()->failure_count)->toBe(1);
});

it('retries an SMTP host that does not resolve without counting a delivery failure', function () {
    SafeWebhookUrl::resolveHostsUsing(fn (): array => []);
    $this->team->emailNotificationSettings->update(['smtp_host' => 'smtp.example.com']);

    expect(fn () => (new EmailChannel)->send($this->team->fresh(), $this->smtpNotification))
        ->toThrow(RuntimeException::class, 'The SMTP host could not be resolved.');

    expect($this->team->emailNotificationSettings()->first()->failure_count)->toBe(0);
});

it('sends to stored hosts with underscores, a trailing dot, or upper case', function (string $storedHost) {
    InstanceSettings::query()->whereKey(0)->update([
        'webhook_allow_localhost' => true,
        'webhook_allowed_internal_hosts' => ['127.0.0.1'],
    ]);
    SafeWebhookUrl::resolveHostsUsing(fn (string $host): array => in_array($host, ['mail_server', 'smtp.example.com'], true) ? ['127.0.0.1'] : []);
    // Nothing listens on port 1, so the connection to the checked address is refused at once.
    $this->team->emailNotificationSettings->update(['smtp_host' => $storedHost, 'smtp_port' => 1, 'smtp_encryption' => 'none']);

    expect(fn () => (new EmailChannel)->send($this->team->fresh(), $this->smtpNotification))
        ->toThrow(TransportException::class, '127.0.0.1:1');

    expect($this->team->emailNotificationSettings()->first()->failure_count)->toBe(0);
})->with(['mail_server', 'smtp.example.com.', 'SMTP.Example.COM']);
