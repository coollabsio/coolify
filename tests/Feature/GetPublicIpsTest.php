<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Process::fake([
        'curl -4s --max-time 10 https://ifconfig.io' => "203.0.113.1\n",
        'curl -6s --max-time 10 https://ifconfig.io' => "2001:db8::1\n",
    ]);
});

it('detects the public IPs when the settings are empty', function () {
    InstanceSettings::forceCreate(['id' => 0]);

    get_public_ips();

    $settings = InstanceSettings::find(0);
    expect($settings->public_ipv4)->toBe('203.0.113.1')
        ->and($settings->public_ipv6)->toBe('2001:db8::1');
});

it('keeps public IPs that are already set', function () {
    InstanceSettings::forceCreate(['id' => 0, 'public_ipv4' => '203.0.113.21', 'public_ipv6' => '2001:db8::21']);

    get_public_ips();

    $settings = InstanceSettings::find(0);
    expect($settings->public_ipv4)->toBe('203.0.113.21')
        ->and($settings->public_ipv6)->toBe('2001:db8::21');
    Process::assertNothingRan();
});

it('detects only the address family that is empty', function () {
    InstanceSettings::forceCreate(['id' => 0, 'public_ipv4' => '203.0.113.21']);

    get_public_ips();

    $settings = InstanceSettings::find(0);
    expect($settings->public_ipv4)->toBe('203.0.113.21')
        ->and($settings->public_ipv6)->toBe('2001:db8::1');
    Process::assertNotRan('curl -4s --max-time 10 https://ifconfig.io');
});

it('still detects IPv6 when the IPv4 lookup returns an invalid address', function () {
    Process::fake([
        'curl -4s --max-time 10 https://ifconfig.io' => 'not-an-ip',
        'curl -6s --max-time 10 https://ifconfig.io' => "2001:db8::1\n",
    ]);
    InstanceSettings::forceCreate(['id' => 0]);

    get_public_ips();

    $settings = InstanceSettings::find(0);
    expect($settings->public_ipv4)->toBeNull()
        ->and($settings->public_ipv6)->toBe('2001:db8::1');
});

it('keeps the detected address of one family when the lookup of the other family times out', function (string $failedCommand, string $detectedColumn, string $detectedIp, string $failedColumn) {
    Process::fake([
        $failedCommand => Process::result(exitCode: 28),
        '*' => "{$detectedIp}\n",
    ]);
    InstanceSettings::forceCreate(['id' => 0]);

    get_public_ips();

    $settings = InstanceSettings::find(0);
    expect($settings->$detectedColumn)->toBe($detectedIp)
        ->and($settings->$failedColumn)->toBeNull();
})->with([
    'IPv6 times out' => ['curl -6s --max-time 10 https://ifconfig.io', 'public_ipv4', '203.0.113.1', 'public_ipv6'],
    'IPv4 times out' => ['curl -4s --max-time 10 https://ifconfig.io', 'public_ipv6', '2001:db8::1', 'public_ipv4'],
]);
