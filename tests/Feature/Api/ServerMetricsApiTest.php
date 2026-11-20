<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);

    InstanceSettings::forceCreate([
        'id' => 0,
        'is_api_enabled' => true,
    ]);

    $this->team = Team::factory()->create();

    $this->user = User::factory()->create();
    $this->team->members()->attach(
        $this->user->id,
        ['role' => 'owner'],
    );

    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    // Saved quietly: a metrics change restarts Sentinel, which needs SSH.
    $this->server->settings->forceFill([
        'is_metrics_enabled' => false,
    ])->saveQuietly();

    $this->readToken = $this->user
        ->createToken('server-metrics-read', ['read'])
        ->plainTextToken;
});

function serverMetricsHeaders(): array
{
    return [
        'Authorization' => 'Bearer '.test()->readToken,
        'Accept' => 'application/json',
    ];
}

function enableServerMetricsForTest(): void
{
    ServerSetting::withoutEvents(function () {
        test()->server->settings->update([
            'is_metrics_enabled' => true,
            'sentinel_token' => 'server-metrics-test-token',
        ]);
    });

    test()->server->settings->refresh();
}

describe('Server metrics API', function () {
    test('read token can access metrics endpoint', function () {
        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics",
            )
            ->assertOk()
            ->assertJson([
                'cpu' => null,
                'memory' => null,
            ]);
    });

    test('metrics enabled returns cpu and memory history', function () {
        ServerSetting::withoutEvents(function () {
            $this->server->settings->update([
                'is_metrics_enabled' => true,
                'sentinel_token' => 'server-metrics-test-token',
            ]);
        });

        $this->server->settings->refresh();

        fakeRemoteProcesses([
            '*cpu/history*' => Process::result(
                output: json_encode([
                    [
                        'time' => 1787259000000,
                        'percent' => 12.5,
                    ],
                    [
                        'time' => 1787259060000,
                        'percent' => 17.25,
                    ],
                ]),
            ),
            '*memory/history*' => Process::result(
                output: json_encode([
                    [
                        'time' => 1787259000000,
                        'usedPercent' => 42.0,
                    ],
                    [
                        'time' => 1787259060000,
                        'usedPercent' => 44.5,
                    ],
                ]),
            ),
        ]);

        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics?minutes=15",
            )
            ->assertOk()
            ->assertExactJson([
                'cpu' => [
                    [1787259000000, 12.5],
                    [1787259060000, 17.25],
                ],
                'memory' => [
                    [1787259000000, 42.0],
                    [1787259060000, 44.5],
                ],
            ]);

        Process::assertRan(
            fn ($process) => str_contains(remoteProcessScript($process), '/cpu/history') &&
                str_contains(remoteProcessScript($process), 'Authorization: Bearer server-metrics-test-token'),
        );

        Process::assertRan(
            fn ($process) => str_contains(remoteProcessScript($process), '/memory/history') &&
                str_contains(remoteProcessScript($process), 'Authorization: Bearer server-metrics-test-token'),
        );
    });

    test('sentinel error returns 502 without internal details', function () {
        ServerSetting::withoutEvents(function () {
            $this->server->settings->update([
                'is_metrics_enabled' => true,
                'sentinel_token' => 'server-metrics-test-token',
            ]);
        });

        $this->server->settings->refresh();

        Process::fake([
            '*' => Process::result(
                output: json_encode(['error' => 'Unauthorized']),
            ),
        ]);

        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics",
            )
            ->assertStatus(502)
            ->assertExactJson([
                'message' => 'Failed to fetch metrics from Sentinel.',
            ]);
    });

    test('metrics disabled returns null cpu and memory', function () {
        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics?minutes=10",
            )
            ->assertOk()
            ->assertJsonPath('cpu', null)
            ->assertJsonPath('memory', null);
    });

    test('invalid minutes returns validation error', function () {
        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics?minutes=0",
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Validation failed.',
            );
    });

    test('minutes can equal configured Sentinel retention', function () {
        ServerSetting::withoutEvents(function () {
            $this->server->settings->update([
                'sentinel_metrics_history_days' => 7,
            ]);
        });

        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics?minutes=10080",
            )
            ->assertOk();
    });

    test('minutes cannot exceed configured Sentinel retention', function () {
        ServerSetting::withoutEvents(function () {
            $this->server->settings->update([
                'sentinel_metrics_history_days' => 7,
            ]);
        });

        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics?minutes=10081",
            )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath(
                'errors.minutes.0',
                'The requested metrics history exceeds the configured Sentinel retention period of 7 day(s).',
            );
    });

    test('minutes limit follows custom Sentinel retention', function () {
        ServerSetting::withoutEvents(function () {
            $this->server->settings->update([
                'sentinel_metrics_history_days' => 14,
            ]);
        });

        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics?minutes=20160",
            )
            ->assertOk();

        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics?minutes=20161",
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.minutes.0',
                'The requested metrics history exceeds the configured Sentinel retention period of 14 day(s).',
            );
    });

    test('non integer minutes returns validation error', function () {
        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics?minutes=abc",
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Validation failed.',
            );
    });

    test('server owned by another team returns 404', function () {
        $otherTeam = Team::factory()->create();

        $otherServer = Server::factory()->create([
            'team_id' => $otherTeam->id,
        ]);

        $this->withHeaders(serverMetricsHeaders())
            ->getJson(
                "/api/v1/servers/{$otherServer->uuid}/metrics",
            )
            ->assertNotFound()
            ->assertJsonPath(
                'message',
                'Server not found.',
            );
    });

    test('token without read ability is rejected', function () {
        $writeToken = $this->user
            ->createToken('server-metrics-write', ['write'])
            ->plainTextToken;

        $this->withToken($writeToken)
            ->getJson(
                "/api/v1/servers/{$this->server->uuid}/metrics",
            )
            ->assertForbidden();
    });

    test('from and to set the Sentinel history window', function () {
        enableServerMetricsForTest();
        $this->travelTo('2026-11-20 12:00:00');

        fakeRemoteProcesses([
            '*cpu/history*' => Process::result(output: json_encode([['time' => 1787259000000, 'percent' => 3.0]])),
            '*memory/history*' => Process::result(output: json_encode([['time' => 1787259000000, 'usedPercent' => 4.0]])),
        ]);

        $this->withHeaders(serverMetricsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/metrics?from=2026-11-20T09:00:00Z&to=2026-11-20T10:00:00%2B00:00")
            ->assertOk()
            ->assertExactJson(['cpu' => [[1787259000000, 3.0]], 'memory' => [[1787259000000, 4.0]]]);

        Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), '/cpu/history?from=2026-11-20T09:00:00Z&to=2026-11-20T10:00:00Z"'));
    });

    test('types returns disk per mount, network, and load series', function () {
        enableServerMetricsForTest();

        fakeRemoteProcesses([
            '*disk/history*' => Process::result(output: json_encode([
                ['time' => 1000, 'mount' => '/', 'usedPercent' => 40.0],
                ['time' => 1000, 'mount' => '/boot', 'usedPercent' => 90.0],
                ['time' => 2000, 'mount' => '/', 'usedPercent' => 41.0],
            ])),
            '*network/history*' => Process::result(output: json_encode([
                ['time' => 1000, 'rxBytesPerSec' => 100, 'txBytesPerSec' => 10],
            ])),
            '*load/history*' => Process::result(output: json_encode([
                ['time' => 1000, 'load1' => 0.5, 'load5' => 1.0, 'load15' => 1.5],
            ])),
        ]);

        $this->withHeaders(serverMetricsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/metrics?types=disk,network,load")
            ->assertOk()
            ->assertExactJson([
                'disk' => ['/' => [[1000, 40.0], [2000, 41.0]], '/boot' => [[1000, 90.0]]],
                'network' => ['rx' => [[1000, 100.0]], 'tx' => [[1000, 10.0]]],
                'load' => ['load1' => [[1000, 0.5]], 'load5' => [[1000, 1.0]], 'load15' => [[1000, 1.5]]],
            ]);
    });

    test('requested types are null when metrics are disabled', function () {
        $this->withHeaders(serverMetricsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/metrics?types=disk,load")
            ->assertOk()
            ->assertExactJson(['disk' => null, 'load' => null]);
    });

    test('unknown type returns validation error', function () {
        $this->withHeaders(serverMetricsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/metrics?types=cpu,gpu")
            ->assertStatus(422)
            ->assertJsonPath('errors.types.0', 'Unknown metric types: gpu. Use cpu, memory, disk, network, or load.');
    });

    test('minutes cannot be combined with from', function () {
        $this->withHeaders(serverMetricsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/metrics?minutes=5&from=".now()->subHour()->toIso8601ZuluString())
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('minutes');
    });

    test('to must be after from', function () {
        $this->withHeaders(serverMetricsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/metrics?from=2026-11-20T10:00:00Z&to=2026-11-20T09:00:00Z")
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('to');
    });

    test('from cannot be older than configured Sentinel retention', function () {
        ServerSetting::withoutEvents(fn () => $this->server->settings->update(['sentinel_metrics_history_days' => 7]));
        $this->travelTo('2026-11-20 12:00:00');

        $this->withHeaders(serverMetricsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/metrics?from=2026-11-13T11:59:00Z")
            ->assertStatus(422)
            ->assertJsonPath('errors.from.0', 'The requested metrics history exceeds the configured Sentinel retention period of 7 day(s).');

        $this->withHeaders(serverMetricsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/metrics?from=2026-11-13T12:01:00Z")
            ->assertOk();
    });

    test('request without token is rejected', function () {
        $this->getJson(
            "/api/v1/servers/{$this->server->uuid}/metrics",
        )->assertUnauthorized();
    });
});
