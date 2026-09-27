<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Process::fake();

    $user = User::factory()->create();
    $team = $user->teams()->first();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->server->proxy->set('type', 'TRAEFIK');
    $this->server->save();
});

/**
 * Toggle analytics like ConfigureTrafficAnalytics does and run the given Traefik commands through the helper.
 *
 * @param  array<int, string>  $commands
 * @return array<int, string>
 */
function applyTrafficAnalyticsToCommands(Server $server, bool $enabled, array $commands): array
{
    $server->settings->is_traffic_analytics_enabled = $enabled;
    $server->settings->save();
    $server->refresh();

    $configuration = Yaml::dump(['services' => ['traefik' => ['command' => $commands]]], 12, 2);
    $result = applyTrafficAnalyticsToProxyConfiguration($server, $configuration);

    $server->refresh();

    return Yaml::parse($result)['services']['traefik']['command'];
}

/**
 * @param  array<int, string>  $commands
 * @return array<int, string>
 */
function accessLogFlagsIn(array $commands): array
{
    return array_values(array_filter(
        $commands,
        fn (string $command): bool => $command === '--accesslog' || str_starts_with($command, '--accesslog=') || str_starts_with($command, '--accesslog.')
    ));
}

it('restores a user --accesslog=true after enabling and disabling analytics', function () {
    $userCommands = ['--providers.docker=true', '--accesslog=true'];

    $enabled = applyTrafficAnalyticsToCommands($this->server, true, $userCommands);

    expect(accessLogFlagsIn($enabled))->toBe(traefikAccessLogCommands(true))
        ->and(array_count_values($enabled)['--accesslog=true'])->toBe(1);

    $disabled = applyTrafficAnalyticsToCommands($this->server, false, $enabled);

    expect($disabled)->toBe($userCommands)
        ->and($this->server->proxy->get('traffic_analytics_user_accesslog_commands'))->toBeNull();
});

it('replaces a conflicting user access log format while enabled and restores it when disabled', function () {
    $userCommands = ['--providers.docker=true', '--accesslog=true', '--accesslog.format=common'];

    $enabled = applyTrafficAnalyticsToCommands($this->server, true, $userCommands);

    expect($enabled)->not->toContain('--accesslog.format=common')
        ->and(array_values(array_filter($enabled, fn (string $command): bool => str_starts_with($command, '--accesslog.format='))))
        ->toBe(['--accesslog.format=json']);

    $disabled = applyTrafficAnalyticsToCommands($this->server, false, $enabled);

    expect($disabled)->toBe($userCommands);
});

it('remembers user access log filters while enabled and restores them exactly when disabled', function () {
    $userCommands = [
        '--providers.docker=true',
        '--accesslog=true',
        '--accesslog.filters.statuscodes=400-599',
        '--accesslog.filters.minduration=10ms',
        '--accesslog.bufferingsize=100',
    ];

    $enabled = applyTrafficAnalyticsToCommands($this->server, true, $userCommands);

    expect(accessLogFlagsIn($enabled))->toBe(traefikAccessLogCommands(true))
        ->and($this->server->proxy->get('traffic_analytics_user_accesslog_commands'))->toBe([
            '--accesslog=true',
            '--accesslog.filters.statuscodes=400-599',
            '--accesslog.filters.minduration=10ms',
            '--accesslog.bufferingsize=100',
        ]);

    $disabled = applyTrafficAnalyticsToCommands($this->server, false, $enabled);

    expect($disabled)->toBe($userCommands);
});

it('is idempotent when analytics is enabled twice', function () {
    $userCommands = ['--providers.docker=true', '--accesslog=true', '--accesslog.format=common'];

    $first = applyTrafficAnalyticsToCommands($this->server, true, $userCommands);
    $second = applyTrafficAnalyticsToCommands($this->server, true, $first);

    expect($second)->toBe($first)
        ->and($this->server->proxy->get('traffic_analytics_user_accesslog_commands'))->toBe(['--accesslog=true', '--accesslog.format=common']);

    $disabled = applyTrafficAnalyticsToCommands($this->server, false, $second);

    expect($disabled)->toBe($userCommands);
});

it('is idempotent when analytics is disabled twice', function () {
    $userCommands = ['--providers.docker=true', '--accesslog=true'];

    $enabled = applyTrafficAnalyticsToCommands($this->server, true, $userCommands);
    $disabled = applyTrafficAnalyticsToCommands($this->server, false, $enabled);
    $disabledAgain = applyTrafficAnalyticsToCommands($this->server, false, $disabled);

    expect($disabledAgain)->toBe($userCommands);
});

it('keeps a user --accesslog=true when analytics was never enabled', function () {
    $userCommands = ['--providers.docker=true', '--accesslog=true', '--accesslog.format=common'];

    expect(applyTrafficAnalyticsToCommands($this->server, false, $userCommands))->toBe($userCommands);
});

it('removes only the managed flags when disabling analytics that was enabled before flags were remembered', function () {
    $legacyEnabled = [
        '--providers.docker=true',
        '--accesslog.bufferingsize=100',
        ...traefikAccessLogCommands(true),
    ];

    $disabled = applyTrafficAnalyticsToCommands($this->server, false, $legacyEnabled);

    expect($disabled)->toBe(['--providers.docker=true', '--accesslog.bufferingsize=100']);
});

it('remembers only user flags when re-enabling analytics that was enabled before flags were remembered', function () {
    $legacyEnabled = [
        '--providers.docker=true',
        '--accesslog.bufferingsize=100',
        ...traefikAccessLogCommands(true),
    ];

    $enabled = applyTrafficAnalyticsToCommands($this->server, true, $legacyEnabled);

    expect(accessLogFlagsIn($enabled))->toBe(traefikAccessLogCommands(true))
        ->and($this->server->proxy->get('traffic_analytics_user_accesslog_commands'))->toBe(['--accesslog.bufferingsize=100']);

    $disabled = applyTrafficAnalyticsToCommands($this->server, false, $enabled);

    expect($disabled)->toBe(['--providers.docker=true', '--accesslog.bufferingsize=100']);
});

it('keeps a custom --accesslog=true when resetting to the default configuration with analytics off', function () {
    $this->server->settings->is_traffic_analytics_enabled = false;
    $this->server->settings->save();

    $existing = Yaml::dump(['services' => ['traefik' => ['command' => [
        '--providers.docker=true',
        '--accesslog=true',
        '--entrypoints.http.forwardedHeaders.trustedIPs=173.245.48.0/20',
    ]]]], 12, 2);

    $server = $this->server->fresh();
    $customCommands = extractCustomProxyCommands($server, $existing);
    $commands = Yaml::parse(generateDefaultProxyConfiguration($server, $customCommands))['services']['traefik']['command'];

    expect($commands)->toContain('--accesslog=true')
        ->toContain('--entrypoints.http.forwardedHeaders.trustedIPs=173.245.48.0/20')
        ->not->toContain('--accesslog.format=json')
        ->not->toContain('--accesslog.filepath=/traefik/access.log');
});

it('keeps the remembered user flags when resetting to the default configuration with analytics on', function () {
    $userCommands = ['--providers.docker=true', '--accesslog=true', '--accesslog.format=common'];
    $enabled = applyTrafficAnalyticsToCommands($this->server, true, $userCommands);

    $server = $this->server->fresh();
    $existing = Yaml::dump(['services' => ['traefik' => ['command' => $enabled]]], 12, 2);
    $commands = Yaml::parse(generateDefaultProxyConfiguration($server, extractCustomProxyCommands($server, $existing)))['services']['traefik']['command'];

    expect(accessLogFlagsIn($commands))->toBe(traefikAccessLogCommands(true))
        ->and($server->fresh()->proxy->get('traffic_analytics_user_accesslog_commands'))->toBe(['--accesslog=true', '--accesslog.format=common']);

    $disabled = applyTrafficAnalyticsToCommands($server, false, $commands);

    expect(accessLogFlagsIn($disabled))->toBe(['--accesslog=true', '--accesslog.format=common']);
});
