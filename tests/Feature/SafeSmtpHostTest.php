<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Rules\SafeSmtpHost;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.coolify.self_hosted' => true]);
});

afterEach(function () {
    SafeWebhookUrl::resolveHostsUsing(null);
});

function smtpHostErrors(string $host): array
{
    return Validator::make(['smtp_host' => $host], ['smtp_host' => [new SafeSmtpHost]])->errors()->all();
}

it('accepts Docker service names with underscores', function () {
    SafeWebhookUrl::resolveHostsUsing(fn (string $host): array => $host === 'mail_server' ? ['172.18.0.5'] : []);

    expect(smtpHostErrors('mail_server'))->toBe([]);
});

it('accepts a trailing dot, upper case, and bracketed IPv6 addresses', function (string $host) {
    SafeWebhookUrl::resolveHostsUsing(fn (string $host): array => $host === 'smtp.example.com' ? ['93.184.216.34'] : []);

    expect(smtpHostErrors($host))->toBe([]);
})->with([
    'trailing dot' => 'smtp.example.com.',
    'upper case' => 'SMTP.Example.COM',
    'bracketed IPv6' => '[2606:4700::1111]',
]);

it('blocks a bracketed IPv6 loopback address', function () {
    expect(smtpHostErrors('[::1]'))->toBe([
        'The SMTP host points to a local or private address that is not allowed. Ask your Coolify administrator to allow this host.',
    ]);
});

it('connects stored hosts with a trailing dot or upper case to the checked address', function (string $host) {
    SafeWebhookUrl::resolveHostsUsing(fn (string $host): array => $host === 'smtp.example.com' ? ['93.184.216.34'] : []);

    expect(SafeSmtpHost::connectionAddress($host))->toBe('93.184.216.34');
})->with(['smtp.example.com.', 'SMTP.Example.COM']);

it('rejects hosts that are not a hostname or an IP address', function (string $host) {
    SafeWebhookUrl::resolveHostsUsing(fn (): array => ['93.184.216.34']);

    expect(smtpHostErrors($host))->toBe(['The SMTP host must be a valid hostname or IP address.']);
})->with([
    'space' => 'mail server',
    'semicolon' => 'mail;example.com',
    'url' => 'https://mail.example.com',
    'port' => 'mail.example.com:25',
    'empty label' => 'mail..example.com',
    'leading hyphen' => '-mail.example.com',
    'two trailing dots' => 'mail.example.com..',
    'only a dot' => '.',
    'newline' => "mail.example.com\nRCPT",
    'long label' => str_repeat('a', 64).'.example.com',
]);

it('tells team admins who are not instance admins to ask the Coolify administrator', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);

    expect(smtpHostErrors('127.0.0.1'))->toBe([
        'The SMTP host points to a local or private address that is not allowed. Ask your Coolify administrator to allow this host.',
    ]);
});

it('shows instance admins where to allow the host', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);

    expect(smtpHostErrors('127.0.0.1'))->toBe([
        'The SMTP host points to a local or private address that is not allowed. Configure allowed internal targets: '.route('settings.advanced').'#endpoint-section',
    ]);
});

it('returns the checked IPv4 address to connect to', function () {
    SafeWebhookUrl::resolveHostsUsing(fn (): array => ['2606:4700::1', '93.184.216.34']);

    expect(SafeSmtpHost::connectionAddress('smtp.example.com'))->toBe('93.184.216.34')
        ->and(SafeSmtpHost::connectionAddress('93.184.216.34'))->toBe('93.184.216.34');
});

it('marks a host that points to a blocked address as not allowed', function (string $host, array $ips) {
    SafeWebhookUrl::resolveHostsUsing(fn (): array => $ips);

    expect(fn () => SafeSmtpHost::connectionAddress($host))
        ->toThrow(fn (RuntimeException $e) => expect($e->getCode())->toBe(SafeSmtpHost::HOST_NOT_ALLOWED));
})->with([
    'loopback IP' => ['127.0.0.1', []],
    'metadata IP' => ['169.254.169.254', []],
    'resolves to loopback' => ['smtp.example.com', ['127.0.0.1']],
    'invalid stored host' => ['mail;example.com', ['93.184.216.34']],
]);

it('marks a host that does not resolve as a temporary failure', function () {
    SafeWebhookUrl::resolveHostsUsing(fn (): array => []);

    expect(fn () => SafeSmtpHost::connectionAddress('smtp.example.com'))
        ->toThrow(fn (RuntimeException $e) => expect($e->getCode())->toBe(SafeSmtpHost::HOST_UNRESOLVED));
});

it('resolves SMTP hosts with the system resolver before the custom DNS servers', function () {
    InstanceSettings::query()->whereKey(0)->update(['custom_dns_servers' => '1.1.1.1']);

    $rule = new class extends SafeWebhookUrl
    {
        public array $system = ['10.0.0.25'];

        protected function resolveHostWithCustomDnsServers(string $host, array $dnsServers): array
        {
            return ['93.184.216.34'];
        }

        protected function resolveHostWithSystemDns(string $host): array
        {
            return $this->system;
        }
    };

    $smtpRule = $rule::forSmtpHost();
    $smtpRule->system = ['10.0.0.25'];
    expect($smtpRule->checkConnectionHost('mail.corp.example'))->toBe(['status' => 'allowed', 'ips' => ['10.0.0.25']]);

    $smtpRule->system = [];
    expect($smtpRule->checkConnectionHost('mail.corp.example'))->toBe(['status' => 'allowed', 'ips' => ['93.184.216.34']]);
});
