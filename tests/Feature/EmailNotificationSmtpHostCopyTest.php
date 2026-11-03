<?php

use App\Livewire\Notifications\Email as EmailNotification;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.coolify.self_hosted' => true]);
    SafeWebhookUrl::resolveHostsUsing(fn (string $host): array => $host === 'smtp.example.com' ? ['93.184.216.34'] : []);

    $this->team = Team::factory()->create();
    $this->instanceAdmin = User::factory()->create();
    $this->instanceAdmin->teams()->attach(Team::factory()->create(['id' => 0]), ['role' => 'admin']);
    $this->instanceAdmin->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($this->instanceAdmin);
    session(['currentTeam' => $this->team]);
});

afterEach(function () {
    SafeWebhookUrl::resolveHostsUsing(null);
});

function copyInstanceSmtpSettings(string $host): void
{
    InstanceSettings::query()->findOrFail(0)->update([
        'smtp_enabled' => true,
        'smtp_host' => $host,
        'smtp_port' => 587,
        'smtp_encryption' => 'starttls',
        'smtp_from_address' => 'instance@example.com',
        'smtp_from_name' => 'Instance',
        'smtp_password' => 'instance-smtp-secret',
    ]);
}

it('copies an instance SMTP host that teams may use', function () {
    copyInstanceSmtpSettings('smtp.example.com');

    Livewire::test(EmailNotification::class)
        ->call('copyFromInstanceSettings')
        ->assertHasNoErrors();

    $settings = $this->team->emailNotificationSettings()->first();
    expect($settings->smtp_host)->toBe('smtp.example.com')
        ->and($settings->smtp_enabled)->toBeTrue();
});

it('does not copy an instance SMTP host that teams may not use', function () {
    copyInstanceSmtpSettings('localhost');

    Livewire::test(EmailNotification::class)
        ->call('copyFromInstanceSettings')
        ->assertHasErrors('smtpHost')
        ->assertSet('smtpHost', null)
        ->assertSet('smtpPassword', null);

    $settings = $this->team->emailNotificationSettings()->first();
    expect($settings->smtp_host)->toBeNull()
        ->and($settings->smtp_enabled)->toBeFalse()
        ->and($settings->smtp_password)->toBeNull();
});

it('checks a changed SMTP host when the whole form is saved', function () {
    Livewire::test(EmailNotification::class)
        ->set('smtpHost', '127.0.0.1')
        ->call('submit')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->team->emailNotificationSettings()->first()->smtp_host)->toBeNull();
});
