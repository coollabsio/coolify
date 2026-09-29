<?php

use App\Actions\Sentinel\FetchNodeLogs;
use App\Livewire\Node\Logs;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Storage::fake('ssh-keys');
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'name' => 'Logs Node',
        'ip' => '192.0.2.20',
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    Cache::put($this->node->cacheKey(), ['status' => 'connected', 'capabilities' => ['logs.read.v1']]);
});

function fluxLogResponse(string $source = 'sentinel', array $events = []): array
{
    return [
        'command_id' => 'command-1',
        'observed_at_unix_ms' => 1_789_140_000_000,
        'source' => $source,
        'truncated' => true,
        'events' => $events ?: [[
            'timestamp_unix_ms' => 1_789_140_000_000,
            'level' => 'warn',
            'component' => 'sentinel::flux',
            'message' => 'reconnecting to Flux',
            'fields' => ['attempt' => '2'],
        ]],
    ];
}

function journalLine(array $entry): string
{
    return json_encode([
        '__REALTIME_TIMESTAMP' => '1789140000123456',
        'PRIORITY' => '6',
        '_PID' => '4242',
        'MESSAGE' => 'started',
        ...$entry,
    ]);
}

function fakeJournal(string $output): void
{
    Process::fake(['*' => Process::result(output: $output)]);
}

function assertJournalCommandRan(string $unit, int $limit): void
{
    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        "journalctl --unit '{$unit}' --no-pager --output json --lines {$limit}",
    ));
}

it('reads Sentinel logs through Flux', function () {
    Http::fake(['http://flux:7080/v1/commands/logs.read' => Http::response(fluxLogResponse())]);
    Process::fake();

    $result = FetchNodeLogs::run($this->node, 'sentinel', 150);

    expect($result)->toMatchArray(['transport' => 'flux', 'source' => 'sentinel', 'truncated' => true])
        ->and($result['events'][0])->toBe([
            'timestamp_unix_ms' => 1_789_140_000_000,
            'level' => 'warn',
            'component' => 'sentinel::flux',
            'message' => 'reconnecting to Flux',
            'fields' => ['attempt' => '2'],
        ]);
    Http::assertSent(fn ($request) => $request->url() === 'http://flux:7080/v1/commands/logs.read'
        && $request->hasHeader('Authorization', 'Bearer internal-secret')
        && $request['server_id'] === $this->node->uuid
        && $request['source'] === 'sentinel'
        && $request['limit'] === 150);
    Process::assertNothingRan();
});

it('falls back to the journal over SSH when Flux cannot serve the request', function (Closure $fakeFlux) {
    $fakeFlux();
    fakeJournal(journalLine(['MESSAGE' => 'corrosion ready']));

    $result = FetchNodeLogs::run($this->node, 'corrosion', 200);

    expect($result['transport'])->toBe('ssh')
        ->and($result['source'])->toBe('corrosion')
        ->and($result['truncated'])->toBeFalse()
        ->and($result['events'])->toBe([[
            'timestamp_unix_ms' => 1_789_140_000_123,
            'level' => 'info',
            'component' => 'corrosion',
            'message' => 'corrosion ready',
            'fields' => ['pid' => '4242'],
        ]]);
    assertJournalCommandRan('corrosion.service', 200);
})->with([
    'node not connected' => [fn () => Http::fake(['*' => Http::response(['error' => 'not connected'], 404)])],
    'command not supported' => [fn () => Http::fake(['*' => Http::response(['error' => 'unsupported'], 409)])],
    'flux unreachable' => [fn () => Http::fake(fn () => throw new ConnectionException('Connection refused'))],
]);

it('uses SSH without calling Flux when the Node lacks the log capability', function () {
    Cache::put($this->node->cacheKey(), ['status' => 'connected', 'capabilities' => ['system.info.v1']]);
    Http::fake();
    fakeJournal(journalLine([]));

    $result = FetchNodeLogs::run($this->node, 'discovery_dns', 50);

    expect($result['transport'])->toBe('ssh')
        ->and($result['events'][0]['component'])->toBe('coolify-discovery-dns');
    Http::assertNothingSent();
    assertJournalCommandRan('coolify-discovery-dns.service', 50);
});

it('always reads the system journal source over SSH', function () {
    Http::fake();
    fakeJournal(journalLine(['PRIORITY' => '3', 'MESSAGE' => 'sentinel.service: Failed with result exit-code.']));

    $result = FetchNodeLogs::run($this->node, 'journal', 100);

    expect($result['transport'])->toBe('ssh')
        ->and($result['source'])->toBe('journal')
        ->and($result['events'][0]['level'])->toBe('error')
        ->and($result['events'][0]['component'])->toBe('sentinel');
    Http::assertNothingSent();
    assertJournalCommandRan('sentinel.service', 100);
});

it('reports Flux failures and invalid responses without a silent SSH fallback', function (Closure $fakeFlux, string $message) {
    $fakeFlux();
    Process::fake();

    expect(fn () => FetchNodeLogs::run($this->node, 'sentinel'))->toThrow(RuntimeException::class, $message);
    Process::assertNothingRan();
})->with([
    'server error' => [fn () => Http::fake(['*' => Http::response(['error' => 'timeout'], 504)]), 'Flux could not read Sentinel logs (HTTP 504).'],
    'invalid level' => [fn () => Http::fake(['*' => Http::response(fluxLogResponse(events: [[
        'timestamp_unix_ms' => 1, 'level' => 'fatal', 'component' => 'x', 'message' => 'y', 'fields' => [],
    ]]))]), 'Flux returned an invalid log response.'],
    'wrong source' => [fn () => Http::fake(['*' => Http::response(fluxLogResponse('corrosion'))]), 'Flux returned an invalid log response.'],
]);

it('rejects unknown sources and out of range limits', function (string $source, int $limit) {
    Http::fake();
    Process::fake();

    expect(fn () => FetchNodeLogs::run($this->node, $source, $limit))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
    Process::assertNothingRan();
})->with([
    'unknown source' => ['sentinel.service; rm -rf /', 100],
    'zero limit' => ['sentinel', 0],
    'limit above maximum' => ['sentinel', 501],
]);

it('parses journald JSON priorities, byte-array messages, and skips malformed lines', function () {
    $output = implode("\n", [
        journalLine(['PRIORITY' => '0', 'MESSAGE' => 'emergency']),
        journalLine(['PRIORITY' => '4', 'MESSAGE' => 'warning']),
        journalLine(['PRIORITY' => '5', 'MESSAGE' => 'notice']),
        journalLine(['PRIORITY' => '7', 'MESSAGE' => 'debugging']),
        json_encode(['__REALTIME_TIMESTAMP' => '1789140000999000', 'MESSAGE' => array_map('ord', str_split('bytes ok'))]),
        'not json',
        json_encode(['MESSAGE' => 'no timestamp']),
        journalLine(['MESSAGE' => null]),
    ]);

    $events = FetchNodeLogs::parseJournal($output, 'sentinel.service');

    expect(array_column($events, 'level'))->toBe(['error', 'warn', 'info', 'debug', 'info'])
        ->and(array_column($events, 'message'))->toBe(['emergency', 'warning', 'notice', 'debugging', 'bytes ok'])
        ->and($events[4]['timestamp_unix_ms'])->toBe(1_789_140_000_999)
        ->and($events[4]['fields'])->toBe([]);
});

it('removes terminal color codes from SSH journal messages', function () {
    $output = journalLine(['MESSAGE' => "\e[2m2026-09-28T12:53:10Z\e[0m \e[32m INFO\e[0m \e[2mcontrol::connection\e[0m\e[2m:\e[0m Sentinel connected"]);

    $events = FetchNodeLogs::parseJournal($output, 'sentinel.service');

    expect($events[0]['message'])->toBe('2026-09-28T12:53:10Z  INFO control::connection: Sentinel connected');
});

it('redacts secrets in SSH journal messages', function () {
    Http::fake();
    $jwt = 'eyJhbGciOiJFZERTQSJ9.eyJzdWIiOiJub2RlIn0.c2lnbmF0dXJl';
    fakeJournal(implode("\n", [
        journalLine(['MESSAGE' => 'request failed Authorization: Bearer abc.def-123']),
        journalLine(['MESSAGE' => "credential {$jwt} rejected"]),
        journalLine(['MESSAGE' => 'connect url=https://x?token=s3cr3t&x=1 password=hunter2 client_secret: "topsecret"']),
    ]));

    $messages = array_column(FetchNodeLogs::run($this->node, 'journal')['events'], 'message');

    expect($messages)->toBe([
        'request failed Authorization: Bearer [redacted]',
        'credential [redacted] rejected',
        'connect url=https://x?token=[redacted]&x=1 password=[redacted] client_secret: "[redacted]"',
    ])->and(implode(' ', $messages))->not->toContain('hunter2')->not->toContain($jwt);
});

it('marks SSH results as truncated when the journal returned the full limit', function () {
    Http::fake(['*' => Http::response([], 404)]);
    fakeJournal(implode("\n", [journalLine([]), journalLine([])]));

    expect(FetchNodeLogs::run($this->node, 'sentinel', 2)['truncated'])->toBeTrue();
});

it('shows the Node logs page and sidebar item to team admins', function () {
    FetchNodeLogs::shouldRun()->andReturn([
        'transport' => 'flux',
        'source' => 'sentinel',
        'truncated' => true,
        'events' => [[
            'timestamp_unix_ms' => 1_789_140_000_000,
            'level' => 'error',
            'component' => 'sentinel::flux',
            'message' => 'handshake failed',
            'fields' => [],
        ]],
    ]);

    $this->get(route('node.show', $this->node->uuid))
        ->assertSuccessful()
        ->assertSee(route('node.logs', $this->node->uuid), false);

    $this->get(route('node.logs', $this->node->uuid))
        ->assertSuccessful()
        ->assertSeeLivewire(Logs::class)
        ->assertSee('Via Flux')
        ->assertSee('Showing 1 event (truncated)')
        ->assertSee('handshake failed');
});

it('filters events by level and reloads when the source changes', function () {
    $events = [
        ['timestamp_unix_ms' => 1, 'level' => 'info', 'component' => 'sentinel', 'message' => 'info event', 'fields' => []],
        ['timestamp_unix_ms' => 2, 'level' => 'warn', 'component' => 'sentinel', 'message' => 'warn event', 'fields' => []],
        ['timestamp_unix_ms' => 3, 'level' => 'error', 'component' => 'sentinel', 'message' => 'error event', 'fields' => []],
    ];
    FetchNodeLogs::shouldRun()->once()->withArgs(fn ($node, $source, $limit) => $source === 'sentinel' && $limit === 200)
        ->andReturn(['transport' => 'flux', 'source' => 'sentinel', 'truncated' => false, 'events' => $events]);
    FetchNodeLogs::shouldRun()->once()->withArgs(fn ($node, $source, $limit) => $source === 'journal' && $limit === 200)
        ->andReturn(['transport' => 'ssh', 'source' => 'journal', 'truncated' => false, 'events' => []]);

    Livewire::test(Logs::class, ['node_uuid' => $this->node->uuid])
        ->set('level', 'warn')
        ->assertSee('warn event')
        ->assertSee('error event')
        ->assertDontSee('info event')
        ->set('level', 'error')
        ->assertDontSee('warn event')
        ->assertSee('error event')
        ->set('source', 'journal')
        ->assertSet('transport', 'ssh')
        ->assertSee('Via SSH fallback');
});

it('validates the requested source and limit before reading logs', function () {
    FetchNodeLogs::shouldRun()->twice()->andReturn(['transport' => 'flux', 'source' => 'sentinel', 'truncated' => false, 'events' => []]);

    Livewire::test(Logs::class, ['node_uuid' => $this->node->uuid])
        ->set('limit', 5000)
        ->assertHasErrors(['limit'])
        ->set('limit', 200)
        ->set('source', 'anything')
        ->assertHasErrors(['source']);
});

it('only polls while auto-refresh is enabled', function () {
    FetchNodeLogs::shouldRun()->twice()->andReturn(['transport' => 'flux', 'source' => 'sentinel', 'truncated' => false, 'events' => []]);

    Livewire::test(Logs::class, ['node_uuid' => $this->node->uuid])
        ->assertDontSeeHtml('wire:poll.5s')
        ->call('pollLogs')
        ->set('autoRefresh', true)
        ->assertSeeHtml('wire:poll.5s="pollLogs"')
        ->call('pollLogs');
});

it('shows a safe error when logs cannot be read', function () {
    FetchNodeLogs::shouldRun()->andThrow(new RuntimeException('ssh: secret host key failure'));

    Livewire::test(Logs::class, ['node_uuid' => $this->node->uuid])
        ->assertSee('Could not read logs from this Node.')
        ->assertDontSee('secret host key failure')
        ->call('refreshLogs')
        ->assertDispatched('error', 'Could not read logs from this Node.');
});

it('forbids team members from reading Node logs', function () {
    FetchNodeLogs::shouldRun()->never();
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->get(route('node.logs', $this->node->uuid))->assertForbidden();
    $this->get(route('node.show', $this->node->uuid))
        ->assertSuccessful()
        ->assertDontSee(route('node.logs', $this->node->uuid), false);
});

it('does not expose logs of a Node owned by another team', function () {
    FetchNodeLogs::shouldRun()->never();
    $foreignTeam = User::factory()->create()->teams()->firstOrFail();
    $foreignNode = Node::factory()->create([
        'team_id' => $foreignTeam->id,
        'private_key_id' => $this->node->private_key_id,
    ]);

    $this->get(route('node.logs', $foreignNode->uuid))->assertNotFound();
});

it('keeps Node logs behind the development feature gate', function () {
    config()->set('constants.sentinel.host_enabled', false);

    $this->get(route('node.logs', $this->node->uuid))->assertNotFound();
});
