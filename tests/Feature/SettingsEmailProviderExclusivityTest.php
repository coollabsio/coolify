<?php

use App\Livewire\SettingsEmail;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = new InstanceSettings;
    $this->settings->id = 0;
    $this->settings->save();
    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->rootTeam, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->rootTeam]);
});

test('enabling SMTP disables Resend in storage', function () {
    $this->settings->update([
        'resend_enabled' => true,
        'resend_api_key' => 're_test_key',
        'smtp_from_address' => 'from@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    Livewire::test(SettingsEmail::class)
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpPort', '587')
        ->set('smtpEncryption', 'starttls')
        ->set('smtpFromAddress', 'from@example.com')
        ->set('smtpFromName', 'Coolify')
        ->call('toggleSmtp');

    $this->settings->refresh();
    expect($this->settings->smtp_enabled)->toBeTrue();
    expect($this->settings->resend_enabled)->toBeFalse();
});

test('enabling Resend disables SMTP in storage', function () {
    $this->settings->update([
        'smtp_enabled' => true,
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_encryption' => 'starttls',
        'smtp_from_address' => 'from@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    Livewire::test(SettingsEmail::class)
        ->set('resendApiKey', 're_test_key')
        ->set('smtpFromAddress', 'from@example.com')
        ->set('smtpFromName', 'Coolify')
        ->call('toggleResend');

    $this->settings->refresh();
    expect($this->settings->resend_enabled)->toBeTrue();
    expect($this->settings->smtp_enabled)->toBeFalse();
});

test('a user who is no longer an instance admin cannot toggle email providers', function (string $action, string $column) {
    $this->settings->update([
        'smtp_enabled' => $column === 'smtp_enabled',
        'resend_enabled' => $column === 'resend_enabled',
        'resend_api_key' => 're_test_key',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_from_address' => 'from@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    $component = Livewire::test(SettingsEmail::class);

    $member = User::factory()->create();
    $member->teams()->attach($this->rootTeam, ['role' => 'member']);
    $this->actingAs($member);

    $component->call($action)->assertDispatched('error');

    expect($this->settings->refresh()->{$column})->toBeTrue();
})->with([
    'SMTP' => ['toggleSmtp', 'smtp_enabled'],
    'Resend' => ['toggleResend', 'resend_enabled'],
]);
