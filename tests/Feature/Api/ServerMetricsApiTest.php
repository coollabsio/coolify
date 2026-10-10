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

    $this->server->settings->update([
        'is_metrics_enabled' => false,
    ]);

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

        Process::fake([
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
            fn ($process) => str_contains($process->command, '/cpu/history') &&
                str_contains($process->command, 'Authorization: Bearer server-metrics-test-token'),
        );

        Process::assertRan(
            fn ($process) => str_contains($process->command, '/memory/history') &&
                str_contains($process->command, 'Authorization: Bearer server-metrics-test-token'),
        );
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

    test('request without token is rejected', function () {
        $this->getJson(
            "/api/v1/servers/{$this->server->uuid}/metrics",
        )->assertUnauthorized();
    });
});
