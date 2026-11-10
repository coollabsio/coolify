<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });
});

it('upstream.json contains traefik branches with patch versions', function () {
    $versionsPath = base_path('upstream.json');
    expect(File::exists($versionsPath))->toBeTrue();

    $versions = json_decode(File::get($versionsPath), true);
    expect($versions)->toHaveKey('traefik');

    $traefikVersions = $versions['traefik'];
    expect($traefikVersions)->toBeArray();

    // Each branch should have format like "v3.6" => "3.6.0"
    foreach ($traefikVersions as $branch => $version) {
        expect($branch)->toMatch('/^v\d+\.\d+$/'); // e.g., "v3.6"
        expect($version)->toMatch('/^\d+\.\d+\.\d+$/'); // e.g., "3.6.0"
    }

    // Track current supported branches so the checker can surface security patches
    // (see https://github.com/coollabsio/coolify/issues/11025)
    expect($traefikVersions)->toHaveKeys(['v3.7', 'v3.6', 'v2.11']);
    expect(version_compare($traefikVersions['v3.7'], '3.7.8', '>='))->toBeTrue();
    expect(version_compare($traefikVersions['v3.6'], '3.6.23', '>='))->toBeTrue();
    expect(version_compare($traefikVersions['v2.11'], '2.11.52', '>='))->toBeTrue();

    // Newest tracked branch should sort as the highest major.minor
    $newestBranch = collect($traefikVersions)
        ->keys()
        ->map(fn (string $branch) => ltrim($branch, 'v'))
        ->sort(SORT_NATURAL)
        ->last();
    expect($newestBranch)->toBe('3.7');
});

it('notification generates correct server proxy URLs', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'name' => 'Test Server',
        'team_id' => $team->id,
        'uuid' => 'test-uuid-123',
    ]);

    $server->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    $notification = new TraefikVersionOutdated(collect([$server]));
    $mail = $notification->toMail($team);

    // Verify the mail has the transformed servers with URLs
    expect($mail->viewData['servers'])->toHaveCount(1);
    expect($mail->viewData['servers'][0]['name'])->toBe('Test Server');
    expect($mail->viewData['servers'][0]['uuid'])->toBe('test-uuid-123');
    expect($mail->viewData['servers'][0]['url'])->toBe(base_url().'/server/test-uuid-123/proxy');
    expect($mail->viewData['servers'][0]['outdatedInfo'])->toBeArray();
});

it('notification transforms multiple servers with URLs correctly', function () {
    $team = Team::factory()->create();
    $server1 = Server::factory()->create([
        'name' => 'Server 1',
        'team_id' => $team->id,
        'uuid' => 'uuid-1',
    ]);
    $server1->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    $server2 = Server::factory()->create([
        'name' => 'Server 2',
        'team_id' => $team->id,
        'uuid' => 'uuid-2',
    ]);
    $server2->outdatedInfo = [
        'current' => '3.4.0',
        'latest' => '3.6.0',
        'type' => 'minor_upgrade',
        'upgrade_target' => 'v3.6',
    ];

    $servers = collect([$server1, $server2]);
    $notification = new TraefikVersionOutdated($servers);
    $mail = $notification->toMail($team);

    // Verify both servers have URLs
    expect($mail->viewData['servers'])->toHaveCount(2);

    expect($mail->viewData['servers'][0]['name'])->toBe('Server 1');
    expect($mail->viewData['servers'][0]['url'])->toBe(base_url().'/server/uuid-1/proxy');

    expect($mail->viewData['servers'][1]['name'])->toBe('Server 2');
    expect($mail->viewData['servers'][1]['url'])->toBe(base_url().'/server/uuid-2/proxy');
});

it('notification uses base_url helper not config app.url', function () {
    config(['app.url' => 'http://localhost']);
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => 'https://coolify.example.com']);
    Once::flush();

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'name' => 'Test Server',
        'team_id' => $team->id,
        'uuid' => 'test-uuid',
    ]);

    $server->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    $notification = new TraefikVersionOutdated(collect([$server]));
    $mail = $notification->toMail($team);

    // Verify URL starts with base_url() not config('app.url')
    $generatedUrl = $mail->viewData['servers'][0]['url'];
    expect($generatedUrl)->toStartWith(base_url());
    expect($generatedUrl)->toBe('https://coolify.example.com/server/test-uuid/proxy');
    expect($generatedUrl)->not->toContain('localhost');
});
