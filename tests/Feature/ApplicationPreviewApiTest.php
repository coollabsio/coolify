<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->bearerToken = createTeamApiToken($this->user, $this->team, ['*']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    CleanupPreviewDeployment::shouldRun()->andReturn([
        'cancelled_deployments' => 0,
        'killed_containers' => 0,
        'status' => 'success',
    ]);
});

function previewAuthHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function createTeamApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function createPreview(Application $application, int $pullRequestId): ApplicationPreview
{
    return ApplicationPreview::create([
        'uuid' => (string) new Cuid2,
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => "https://github.com/example/repo/pull/{$pullRequestId}",
        'fqdn' => "pr-{$pullRequestId}.example.com",
    ]);
}

describe('DELETE /api/v1/applications/{uuid}/previews/{pull_request_id}', function () {
    test('returns 401 when no bearer token provided', function () {
        $response = $this->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/42");

        $response->assertUnauthorized();
    });

    test('returns 404 when application uuid does not exist', function () {
        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->deleteJson('/api/v1/applications/nonexistent-uuid/previews/42');

        $response->assertNotFound()
            ->assertJson(['message' => 'Application not found.']);
    });

    test('returns 404 when preview does not exist for the application', function () {
        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/9999");

        $response->assertNotFound()
            ->assertJson(['message' => 'Preview not found.']);
    });

    test('returns 422 when pull_request_id is not a positive integer', function () {
        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/0");

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid pull_request_id.']);
    });

    test('soft-deletes the preview and returns 200 on success', function () {
        $preview = createPreview($this->application, 42);

        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/42");

        $response->assertOk()
            ->assertJson(['message' => 'Preview deletion request queued.']);

        expect($preview->fresh()->trashed())->toBeTrue();
    });

    test('returns 403 when token lacks write ability', function () {
        $readOnlyToken = createTeamApiToken($this->user, $this->team, ['read']);
        createPreview($this->application, 7);

        $response = $this->withHeaders(previewAuthHeaders($readOnlyToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/previews/7");

        $response->assertForbidden();
    });
});

describe('PATCH /api/v1/applications/{uuid}/previews/{pull_request_id}', function () {
    test('stores preview domain ports separately from portless public domains', function () {
        $preview = createPreview($this->application, 42);

        $response = $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/42", [
                'domains' => 'https://one.example.com:3000,https://two.example.com:8080',
            ])
            ->assertOk()
            ->assertJsonPath('domains', 'https://one.example.com,https://two.example.com');

        expect($response->json('domain_port_overrides'))->toBe([
            'https://one.example.com' => 3000,
            'https://two.example.com' => 8080,
        ]);

        expect($preview->fresh()->fqdn)->toBe('https://one.example.com,https://two.example.com')
            ->and($preview->fresh()->domain_port_overrides)->toBe([
                'https://one.example.com' => 3000,
                'https://two.example.com' => 8080,
            ]);
    });

    test('clears an existing preview domain override when the submitted domain is portless', function () {
        $preview = createPreview($this->application, 43);
        $preview->update(['fqdn' => 'https://preview.example.com:8080']);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/43", [
                'domains' => 'https://preview.example.com',
            ])
            ->assertOk()
            ->assertJsonPath('domain_port_overrides', null);

        expect($preview->fresh()->fqdn)->toBe('https://preview.example.com')
            ->and($preview->fresh()->domain_port_overrides)->toBeNull();
    });

    test('rejects invalid preview domains', function () {
        createPreview($this->application, 44);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/44", [
                'domains' => 'not-a-domain',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domains');
    });

    test('rejects preview domain ports outside the valid TCP range', function (string $domain) {
        createPreview($this->application, 59);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/59", [
                'domains' => $domain,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domains');
    })->with([
        'zero' => 'https://preview.example.com:0',
        'above maximum' => 'https://preview.example.com:65536',
    ]);

    test('returns 403 when token lacks write ability', function () {
        $readOnlyToken = createTeamApiToken($this->user, $this->team, ['read']);
        createPreview($this->application, 45);

        $this->withHeaders(previewAuthHeaders($readOnlyToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/45", [
                'domains' => 'https://preview.example.com:3000',
            ])
            ->assertForbidden();
    });

    test('rejects a non-integer pull request id', function () {
        createPreview($this->application, 1);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/1.9", [
                'domains' => 'https://preview.example.com:3000',
            ])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Invalid pull_request_id.']);
    });

    test('detects conflicts after removing the submitted internal port', function () {
        $otherApplication = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'fqdn' => 'https://taken.example.com',
        ]);
        createPreview($this->application, 46);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/46", [
                'domains' => 'https://taken.example.com:3000',
            ])
            ->assertConflict()
            ->assertJsonPath('conflicts.0.resource_uuid', $otherApplication->uuid);
    });

    test('detects conflicts with another preview domain', function () {
        createPreview($this->application, 47)->update(['fqdn' => 'https://taken-preview.example.com:3000']);
        createPreview($this->application, 48);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/48", [
                'domains' => 'https://taken-preview.example.com:8080',
            ])
            ->assertConflict();
    });

    test('filters preview conflict candidates in the database', function () {
        createPreview($this->application, 56)->update(['fqdn' => 'https://taken-preview.example.com']);
        createPreview($this->application, 57)->update(['fqdn' => 'https://current-preview.example.com']);
        createPreview($this->application, 58)->update(['fqdn' => 'https://unrelated.example.com']);

        $queries = collect();
        DB::listen(function ($query) use ($queries): void {
            if (str_contains($query->sql, 'from "application_previews"')) {
                $queries->push($query->sql);
            }
        });

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/57", [
                'domains' => 'https://taken-preview.example.com',
            ])
            ->assertConflict();

        expect($queries->first(fn (string $sql): bool => str_contains($sql, '"application_id" in (select')
            && str_contains($sql, '"fqdn" is not null')
            && str_contains($sql, '"fqdn" like ?')))->not->toBeNull();
    });

    test('updates Docker Compose preview domains with per-domain ports', function () {
        $this->application->update([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: nginx:alpine\n",
        ]);
        $preview = createPreview($this->application, 49);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/49", [
                'docker_compose_domains' => [
                    ['name' => 'web', 'domain' => 'https://web-preview.example.com:8080'],
                    ['name' => 'api', 'domain' => 'https://api-preview.example.com:3000'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('docker_compose_domains.0.name', 'web')
            ->assertJsonPath('docker_compose_domains.0.domain', 'https://web-preview.example.com')
            ->assertJsonPath('docker_compose_domains.1.name', 'api')
            ->assertJsonPath('docker_compose_domains.1.domain', 'https://api-preview.example.com');

        $preview->refresh();

        expect(json_decode($preview->docker_compose_domains, true))->toBe([
            'web' => ['domain' => 'https://web-preview.example.com'],
            'api' => ['domain' => 'https://api-preview.example.com'],
        ])->and($preview->fqdn)->toBe('https://web-preview.example.com,https://api-preview.example.com')
            ->and($preview->domain_port_overrides)->toBe([
                'https://web-preview.example.com' => 8080,
                'https://api-preview.example.com' => 3000,
            ]);
    });

    test('clears Docker Compose preview port overrides with portless domains', function () {
        $this->application->update([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        ]);
        $preview = createPreview($this->application, 50);
        $preview->update([
            'fqdn' => 'https://web-preview.example.com:8080',
            'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://web-preview.example.com']]),
        ]);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/50", [
                'docker_compose_domains' => [
                    ['name' => 'web', 'domain' => 'https://web-preview.example.com'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('domain_port_overrides', null);

        expect($preview->fresh()->domain_port_overrides)->toBeNull();
    });

    test('rejects unknown Docker Compose preview services', function () {
        $this->application->update([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        ]);
        createPreview($this->application, 51);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/51", [
                'docker_compose_domains' => [
                    ['name' => 'unknown', 'domain' => 'https://unknown.example.com:8080'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('docker_compose_domains');
    });

    test('rejects the same Docker Compose preview domain on different internal ports', function () {
        $this->application->update([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: nginx:alpine\n",
        ]);
        createPreview($this->application, 52);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/52", [
                'docker_compose_domains' => [
                    ['name' => 'web', 'domain' => 'https://duplicate.example.com:8080'],
                    ['name' => 'api', 'domain' => 'https://duplicate.example.com:3000'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('docker_compose_domains');
    });

    test('rejects missing Compose services without changing the preview', function () {
        $this->application->update(['build_pack' => 'dockercompose', 'docker_compose_raw' => '']);
        $preview = createPreview($this->application, 53);
        $originalFqdn = $preview->fresh()->fqdn;

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/53", [
                'docker_compose_domains' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('docker_compose_domains');

        expect($preview->fresh()->fqdn)->toBe($originalFqdn);
    });

    test('rejects the domain field for Compose previews', function () {
        $this->application->update([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        ]);
        createPreview($this->application, 54);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/54", [
                'domains' => 'https://ignored.example.com',
                'docker_compose_domains' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domains');
    });

    test('rejects Docker Compose domains for non-Compose previews', function () {
        createPreview($this->application, 55);

        $this->withHeaders(previewAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}/previews/55", [
                'domains' => 'https://preview.example.com',
                'docker_compose_domains' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('docker_compose_domains');
    });
});
