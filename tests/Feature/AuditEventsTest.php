<?php

use App\Http\Kernel;
use App\Livewire\Project\Service\Heading;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Livewire\Team\AuditLog;
use App\Livewire\Team\Index as TeamIndex;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();

    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Log::spy();
});

test('audit inserts are deferred until after the response', function () {
    $this->withDefer();

    auditLog('ui.project.updated', [
        'team_id' => $this->team->id,
        'project_uuid' => 'project-123',
        'project_name' => 'Website',
    ]);

    expect(AuditEvent::query()->count())->toBe(0);

    defer()->invoke();

    expect(AuditEvent::query()->count())->toBe(1);
});

test('multiple audit inserts in one request are all deferred', function () {
    $this->withDefer();

    auditLog('ui.application.deployed', [
        'team_id' => $this->team->id,
        'application_uuid' => 'app-123',
    ]);
    auditLog('ui.application.updated', [
        'team_id' => $this->team->id,
        'application_uuid' => 'app-123',
    ]);

    defer()->invoke();

    expect(AuditEvent::query()->pluck('event')->all())->toBe([
        'ui.application.deployed',
        'ui.application.updated',
    ]);
});

test('http kernel invokes deferred callbacks', function () {
    $kernel = app(Kernel::class);
    $middleware = (new ReflectionClass($kernel))->getProperty('middleware')->getValue($kernel);

    expect($middleware)->toContain(InvokeDeferredCallbacks::class);
});

test('audit persistence failures do not fail the action', function () {
    Schema::rename('audit_events', 'unavailable_audit_events');

    try {
        expect(fn () => auditLog('ui.project.updated', ['team_id' => $this->team->id]))
            ->not->toThrow(Throwable::class);

        Log::shouldHaveReceived('warning')->once()->with(
            'Audit event persistence failed',
            Mockery::on(fn (array $context): bool => $context === [
                'event' => 'ui.project.updated',
                'exception' => QueryException::class,
            ]),
        );
    } finally {
        Schema::rename('unavailable_audit_events', 'audit_events');
    }
});

test('audit preparation failures log sanitized diagnostics without failing the action', function () {
    $resourceName = new class
    {
        public function __toString(): string
        {
            throw new RuntimeException('sensitive audit metadata');
        }
    };

    expect(fn () => auditLog('ui.project.updated', [
        'team_id' => $this->team->id,
        'project_name' => $resourceName,
        'secret' => 'must not be logged',
    ]))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')->once()->with(
        'Audit event preparation failed',
        Mockery::on(fn (array $context): bool => $context === [
            'event' => 'ui.project.updated',
            'exception' => RuntimeException::class,
        ]),
    );
});

test('audit log persists a structured event for the current team', function () {
    auditLog('ui.application.updated', [
        'application_uuid' => 'app-123',
        'application_name' => 'Website',
        'changed' => ['name'],
    ]);

    $event = AuditEvent::query()->sole();

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->actor_id)->toBe($this->user->id)
        ->and($event->actor_email)->toBe($this->user->email)
        ->and($event->source)->toBe('ui')
        ->and($event->action)->toBe('updated')
        ->and($event->resource_type)->toBe('application')
        ->and($event->resource_uuid)->toBe('app-123')
        ->and($event->resource_name)->toBe('Website')
        ->and($event->metadata['changed'])->toBe(['name']);
});

test('auditable models record authenticated create update and delete actions', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Website project',
    ]);
    $project->update(['name' => 'Renamed project']);
    $project->delete();

    $events = AuditEvent::query()->where('resource_type', 'project')->orderBy('id')->get();

    expect($events->pluck('event')->all())->toBe([
        'ui.project.created',
        'ui.project.updated',
        'ui.project.deleted',
    ])->and($events[1]->metadata['changed_fields'])->toBe(['name']);
});

test('deleting a project dispatches deleted events for its environments', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->sole();
    AuditEvent::query()->delete();

    $project->delete();

    expect(AuditEvent::query()
        ->where('event', 'ui.environment.deleted')
        ->where('resource_uuid', $environment->uuid)
        ->exists())->toBeTrue();
});

test('deleting a team dispatches updated events for transferred system-wide sources', function () {
    Team::factory()->create(['id' => 0]);
    $githubApp = GithubApp::query()->create([
        'name' => 'System GitHub source',
        'team_id' => $this->team->id,
        'is_system_wide' => true,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);
    $gitlabApp = GitlabApp::query()->create([
        'name' => 'System GitLab source',
        'team_id' => $this->team->id,
        'is_system_wide' => true,
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
    ]);
    AuditEvent::query()->delete();

    $this->team->delete();

    expect(AuditEvent::query()
        ->where('event', 'ui.github_app.updated')
        ->where('resource_uuid', $githubApp->uuid)
        ->exists())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('event', 'ui.gitlab_app.updated')
            ->where('resource_uuid', $gitlabApp->uuid)
            ->exists())->toBeTrue();
});

test('auditable model mutations succeed when audit persistence fails', function () {
    Schema::rename('audit_events', 'unavailable_audit_events');

    try {
        $project = Project::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Persisted project',
        ]);
    } finally {
        Schema::rename('unavailable_audit_events', 'audit_events');
    }

    expect($project->exists)->toBeTrue()
        ->and(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});

test('repeated events for the same resource are each persisted', function () {
    auditLog('api.project.updated', [
        'team_id' => $this->team->id,
        'project_uuid' => 'project-123',
        'changed_fields' => ['name'],
    ]);
    auditLog('api.project.updated', [
        'team_id' => $this->team->id,
        'project_uuid' => 'project-123',
        'changed_fields' => ['description'],
    ]);

    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->metadata['changed_fields'])->toBe(['name'])
        ->and($events[1]->metadata['changed_fields'])->toBe(['description']);
});

test('automatic and explicit auditing both preserve their events', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Website project',
    ]);

    auditLog('ui.project.created', [
        'team_id' => $this->team->id,
        'project_uuid' => $project->uuid,
        'project_name' => $project->name,
        'audit_description' => 'Project created through the API',
        'request_field' => 'preserved',
    ]);

    $events = AuditEvent::query()->where('event', 'ui.project.created')->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[1]->description)->toBe('Project created through the API')
        ->and($events[1]->metadata['request_field'])->toBe('preserved');
});

test('auditable models ignore unauthenticated mutations', function () {
    auth()->logout();

    Project::factory()->create(['team_id' => $this->team->id]);

    expect(AuditEvent::query()->count())->toBe(0);
});

test('webhook audits resolve the team from the application', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    AuditEvent::query()->delete();
    auth()->logout();
    session()->forget('currentTeam');

    auditLog('webhook.deployment.queued', [
        'application_uuid' => $application->uuid,
        'application_name' => $application->name,
    ]);

    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'event' => 'webhook.deployment.queued',
        'resource_uuid' => $application->uuid,
    ]);
});

test('unauthenticated webhook failures without a team are preserved', function () {
    auth()->logout();
    session()->forget('currentTeam');

    auditLogWebhookFailure('sentinel', 'token_missing');
    auditLogWebhookFailure('stripe', 'invalid_signature');

    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('event')->all())->toBe([
            'webhook.sentinel.signature_failed',
            'webhook.stripe.signature_failed',
        ])
        ->and($events->pluck('team_id')->all())->toBe([null, null]);
});

test('early Sentinel and Stripe rejections persist unscoped audit events', function () {
    auth()->logout();
    session()->forget('currentTeam');

    $this->postJson('/api/v1/sentinel/push', [])->assertUnauthorized();

    config(['subscription.stripe_webhook_secret' => 'whsec_test']);
    $this->withHeader('Stripe-Signature', 'invalid')
        ->call('POST', '/webhooks/payments/stripe/events', [], [], [], [], '{}')
        ->assertBadRequest();

    expect(AuditEvent::query()->orderBy('id')->pluck('event')->all())->toBe([
        'webhook.sentinel.signature_failed',
        'webhook.stripe.signature_failed',
    ])->and(AuditEvent::query()->whereNotNull('team_id')->doesntExist())->toBeTrue();
});

test('unscoped audit events are only visible to the instance team', function () {
    AuditEvent::factory()->create([
        'team_id' => null,
        'description' => 'Unscoped security failure',
    ]);

    Livewire::test(AuditLog::class)
        ->assertDontSee('Unscoped security failure');

    $instanceTeam = Team::factory()->create(['id' => 0]);
    $instanceTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->user->unsetRelation('teams');
    session(['currentTeam' => $instanceTeam]);

    Livewire::test(AuditLog::class)
        ->assertSee('Unscoped security failure');
});

test('auditable models identify personal access token mutations as api events', function () {
    $newToken = $this->user->createToken('audit-api');
    $newToken->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->actingAs($this->user->withAccessToken($newToken->accessToken->fresh()));

    Project::factory()->create(['team_id' => $this->team->id]);

    expect(AuditEvent::query()->where('resource_type', 'project')->firstOrFail()->event)
        ->toBe('api.project.created');
});

test('API audit events identify the responsible access token', function () {
    $firstToken = $this->user->createToken('first-token');
    $firstToken->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $secondToken = $this->user->createToken('second-token');
    $secondToken->accessToken->forceFill(['team_id' => $this->team->id])->save();

    foreach ([$firstToken->accessToken->fresh(), $secondToken->accessToken->fresh()] as $token) {
        $this->actingAs($this->user->withAccessToken($token));
        auditLog('api.project.updated', ['team_id' => $this->team->id]);
    }

    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events->pluck('actor_token_id')->all())->toBe([
        $firstToken->accessToken->id,
        $secondToken->accessToken->id,
    ])->and($events->pluck('actor_token_name')->all())->toBe([
        'first-token',
        'second-token',
    ]);

    Livewire::test(AuditLog::class)
        ->assertSee('Token: first-token')
        ->assertSee('Token: second-token');
});

test('API model mutations produce one audit event', function () {
    $this->withoutExceptionHandling();
    $token = $this->user->createToken('audit-api', ['root']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $response = $this->withToken($token->plainTextToken)->postJson('/api/v1/projects', [
        'name' => 'Single API audit event',
    ]);

    $response->assertCreated();

    expect(AuditEvent::query()
        ->where('event', 'api.project.created')
        ->where('resource_uuid', $response->json('uuid'))
        ->count())->toBe(1);
});

test('API application updates produce one audit event', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    AuditEvent::query()->delete();

    $token = $this->user->createToken('audit-api', ['root']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->patchJson("/api/v1/applications/{$application->uuid}", ['description' => 'Updated through API'])
        ->assertOk();

    expect(AuditEvent::query()
        ->where('event', 'api.application.updated')
        ->where('resource_uuid', $application->uuid)
        ->count())->toBe(1);
});

test('deployment queue records rollback and cancellation operations', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    AuditEvent::query()->delete();

    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'rollback-deployment',
        'commit' => 'abc123',
        'rollback' => true,
        'status' => 'queued',
    ]);

    $deployment->update(['status' => 'cancelled-by-user']);

    expect(AuditEvent::query()->orderBy('id')->pluck('event')->all())->toBe([
        'ui.application.rollback',
        'ui.deployment.cancelled',
    ]);
});

test('team resource models opt in to automatic auditing', function (string $model) {
    expect(class_uses_recursive($model))->toContain(Auditable::class);
})->with([
    Application::class,
    Service::class,
    Server::class,
    Project::class,
    Environment::class,
    EnvironmentVariable::class,
    SharedEnvironmentVariable::class,
    PrivateKey::class,
    StandalonePostgresql::class,
    StandaloneMysql::class,
    StandaloneMariadb::class,
    StandaloneMongodb::class,
    StandaloneRedis::class,
    StandaloneKeydb::class,
    StandaloneDragonfly::class,
    StandaloneClickhouse::class,
]);

test('withoutAuditLogging suppresses mutations until the outer callback ends', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Original project',
        'description' => 'Original description',
    ]);
    AuditEvent::query()->delete();

    $project->withoutAuditLogging(function () use ($project) {
        $project->update(['name' => 'Outer suppressed']);

        $project->withoutAuditLogging(function () use ($project) {
            $project->update(['description' => 'Inner suppressed']);
        });

        $project->update(['name' => 'Still suppressed']);
    });

    expect(AuditEvent::query()->count())->toBe(0);

    $project->update(['description' => 'Logged after suppression']);

    expect(AuditEvent::query()->pluck('event')->all())->toBe(['ui.project.updated']);
});

test('status-only database updates do not record last online audit changes', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    foreach ([StandaloneClickhouse::class, StandaloneRedis::class] as $model) {
        $attributes = [
            'uuid' => fake()->uuid(),
            'name' => 'Status test database',
            'status' => 'exited',
            'environment_id' => $environment->id,
            'destination_type' => Server::class,
            'destination_id' => 0,
        ];
        if ($model === StandaloneClickhouse::class) {
            $attributes['clickhouse_admin_password'] = 'password';
        }

        $database = $model::create($attributes);
        AuditEvent::query()->delete();

        $database->update(['status' => 'running']);

        expect(AuditEvent::query()->count())->toBe(0);

        $database->update(['name' => 'Renamed status test database']);

        $event = AuditEvent::query()->sole();

        expect($event->metadata['changed_fields'])->toBe(['name']);
        AuditEvent::query()->delete();
    }
});

test('audit log redacts sensitive metadata', function () {
    auditLog('api.application.updated', [
        'team_id' => $this->team->id,
        'application_uuid' => 'app-123',
        'token' => 'secret-token',
        'nested' => ['password' => 'secret-password', 'safe' => 'visible'],
    ]);

    $metadata = AuditEvent::query()->sole()->metadata;

    expect($metadata['token'])->toBe('[REDACTED]')
        ->and($metadata['nested']['password'])->toBe('[REDACTED]')
        ->and($metadata['nested']['safe'])->toBe('visible');
});

test('audit log redacts common credential metadata keys', function (string $key) {
    auditLog('api.application.updated', [
        'team_id' => $this->team->id,
        $key => 'sensitive-value',
    ]);

    expect(AuditEvent::query()->sole()->metadata[$key])->toBe('[REDACTED]');
})->with([
    'api_key',
    'access_key',
    'authorization',
    'cookie',
    'client_secret',
]);

test('audit event classification can use explicit resource and action context', function () {
    auditLog('mcp.control', [
        'team_id' => $this->team->id,
        'resource' => 'application',
        'action' => 'restart',
        'resource_uuid' => 'app-123',
    ]);

    $event = AuditEvent::query()->sole();

    expect($event->resource_type)->toBe('application')
        ->and($event->action)->toBe('restart')
        ->and($event->resource_uuid)->toBe('app-123');
});

test('team invitation audit logs redact the invitation email', function () {
    auditLog('ui.team_invitation.created', [
        'team_id' => $this->team->id,
        'invitation_uuid' => 'invitation-123',
        'invitation_email' => 'invitee@example.com',
        'role' => 'member',
        'via' => 'email',
    ]);

    $metadata = AuditEvent::query()->sole()->metadata;

    expect($metadata['invitation_email'])->toBe('[REDACTED]')
        ->and($metadata['invitation_uuid'])->toBe('invitation-123')
        ->and($metadata['role'])->toBe('member')
        ->and($metadata['via'])->toBe('email');
});

test('audit log page only shows events for the current team', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'description' => 'Website created',
    ]);
    AuditEvent::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'description' => 'Private app deleted',
    ]);

    Livewire::test(AuditLog::class)
        ->assertSee('Website created')
        ->assertDontSee('Private app deleted');
});

test('audit log is available under team settings', function () {
    $this->get('/team/audit-log')
        ->assertSuccessful()
        ->assertSeeLivewire(AuditLog::class);
});

test('team members cannot view the audit log page', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->get('/team/audit-log')->assertForbidden();
});

test('demoted team admins cannot make subsequent audit log requests', function () {
    $component = Livewire::test(AuditLog::class);

    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    auth()->setUser($this->user->fresh());

    $component->set('search', 'deployment')->assertStatus(403);
});

test('team admins can query only their team audit events through the api', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'event' => 'api.project.updated',
        'source' => 'api',
        'action' => 'updated',
        'description' => 'Visible event',
        'actor_email' => 'owner@example.com',
        'actor_token_name' => 'production token',
        'metadata' => ['changed_fields' => ['name']],
        'ip_address' => '192.0.2.1',
        'user_agent' => 'Sensitive user agent',
    ]);
    AuditEvent::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'event' => 'api.project.updated',
        'source' => 'api',
        'action' => 'updated',
        'description' => 'Other team event',
    ]);

    $token = $this->user->createToken('audit-read', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/audit-events?source=api&action=updated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.description', 'Visible event')
        ->assertJsonMissingPath('data.0.actor_email')
        ->assertJsonMissingPath('data.0.actor_token_id')
        ->assertJsonMissingPath('data.0.actor_token_name')
        ->assertJsonMissingPath('data.0.metadata')
        ->assertJsonMissingPath('data.0.ip_address')
        ->assertJsonMissingPath('data.0.user_agent');
});

test('team admins with sensitive read access can query full audit event details', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'actor_email' => 'owner@example.com',
        'actor_token_name' => 'production token',
        'metadata' => ['changed_fields' => ['name']],
        'ip_address' => '192.0.2.1',
        'user_agent' => 'Sensitive user agent',
    ]);

    $token = $this->user->createToken('audit-sensitive-read', ['read', 'read:sensitive']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/audit-events')
        ->assertOk()
        ->assertJsonPath('data.0.actor_email', 'owner@example.com')
        ->assertJsonPath('data.0.actor_token_name', 'production token')
        ->assertJsonPath('data.0.metadata.changed_fields.0', 'name')
        ->assertJsonPath('data.0.ip_address', '192.0.2.1')
        ->assertJsonPath('data.0.user_agent', 'Sensitive user agent');
});

test('tokens without sensitive read access cannot search private audit fields', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'actor_email' => 'private-audit@example.com',
        'description' => 'Public description',
    ]);

    $token = $this->user->createToken('audit-read', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/audit-events?search=private-audit@example.com')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('team members cannot query audit events through the api', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);
    $token = $member->createToken('audit-read', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/audit-events')
        ->assertForbidden();
});

test('audit events api rejects pagination and filter values outside the allowed bounds', function (string $query, string $field) {
    $token = $this->user->createToken('audit-read', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/audit-events?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'per_page below minimum' => ['per_page=0', 'per_page'],
    'per_page above maximum' => ['per_page=101', 'per_page'],
    'page below minimum' => ['page=0', 'page'],
    'unsupported source' => ['source=unknown', 'source'],
    'action longer than 255 characters' => ['action='.str_repeat('a', 256), 'action'],
]);

test('audit events api accepts valid pagination and filter values', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'event' => 'api.project.updated',
        'source' => 'api',
        'action' => 'updated',
        'description' => 'Visible event',
    ]);
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'event' => 'ui.project.created',
        'source' => 'ui',
        'action' => 'created',
        'description' => 'Other event',
    ]);

    $token = $this->user->createToken('audit-read', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/audit-events?per_page=1&source=api&action=updated&search=Visible')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.description', 'Visible event')
        ->assertJsonPath('per_page', 1);
});

test('audit source filter omits the unused system source', function () {
    Livewire::test(AuditLog::class)
        ->assertSee('All sources')
        ->assertSee('Web UI')
        ->assertSee('API')
        ->assertSee('MCP')
        ->assertSee('Webhook')
        ->assertDontSee('System');
});

test('audit action filter includes actions recorded for the current team', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'action' => 'backup_schedule_deleted',
    ]);

    Livewire::test(AuditLog::class)
        ->assertViewHas('actionOptions', fn (array $options): bool => in_array([
            'value' => 'backup_schedule_deleted',
            'label' => 'Backup Schedule Deleted',
        ], $options, true));
});

test('resource clone audit starts only after the destination server capability check', function () {
    $source = file_get_contents(app_path('Livewire/Project/Shared/ResourceOperations.php'));

    expect(strpos($source, "auditLog('ui.resource.clone_started'"))
        ->toBeGreaterThan(strpos($source, 'if (! $server->canHostResources())'));
});

test('pull and restart records the service restart audit event after starting the service', function () {
    $method = new ReflectionMethod(Heading::class, 'pullAndRestartEvent');
    $source = file($method->getFileName());
    $methodSource = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    expect($methodSource)
        ->toContain("auditServiceAction('ui.service.restarted')")
        ->and(strpos($methodSource, 'StartService::run'))->toBeLessThan(strpos($methodSource, 'auditServiceAction'));
});

test('critical operational events persist with their source action and actor', function (string $event) {
    auditLog($event, [
        'team_id' => $this->team->id,
        'resource_uuid' => 'resource-123',
        'resource_name' => 'Test resource',
    ]);

    $auditEvent = AuditEvent::query()->sole();

    expect($auditEvent->event)->toBe($event)
        ->and($auditEvent->source)->toBe(str($event)->before('.')->value())
        ->and($auditEvent->action)->toBe(str($event)->afterLast('.')->value())
        ->and($auditEvent->actor_email)->toBe($this->user->email);
})->with([
    'ui.application.stopped',
    'ui.application.preview_stopped',
    'ui.application.destination_stopped',
    'ui.application.rollback',
    'ui.deployment.cancelled',
    'ui.service.started',
    'ui.service.stopped',
    'ui.service.restarted',
    'ui.database.started',
    'ui.database.stopped',
    'ui.database.restarted',
    'ui.proxy.stopped',
    'ui.proxy.restarted',
    'ui.database.backup_started',
    'ui.database.backup_schedule_deleted',
    'ui.database.import_started',
    'ui.database.restore_started',
    'ui.scheduled_task.executed',
    'ui.api_token.created',
    'ui.api_token.revoked',
    'ui.team_member.role_updated',
    'ui.team_member.removed',
    'ui.team_invitation.created',
    'ui.team_invitation.revoked',
    'ui.server.docker_cleanup_started',
    'ui.server.imported',
    'ui.project.clone_started',
    'ui.resource.clone_started',
    'api.database.started',
    'api.database.stopped',
    'api.database.restarted',
]);

test('audit log table keeps actor details visible in a mobile scroll area', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'actor_name' => 'Visible Actor',
        'actor_token_name' => 'visible-audit-token',
    ]);

    Livewire::test(AuditLog::class)
        ->assertSeeHtml('class="overflow-x-auto"')
        ->assertSeeHtml('min-w-[760px]')
        ->assertSeeHtml('grid-cols-[14rem_minmax(0,1fr)_12rem_9rem]')
        ->assertSeeHtml('class="self-center text-right text-[11px]')
        ->assertSeeHtml('title="Token: visible-audit-token"')
        ->assertSee('Actor')
        ->assertSee('Visible Actor');
});

test('audit log displays source abbreviations in uppercase', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'source' => 'cli',
    ]);

    Livewire::test(AuditLog::class)
        ->assertSee('CLI');
});

test('audit log page filters events by search and action', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'action' => 'created',
        'description' => 'Website created',
        'resource_name' => 'Website',
    ]);
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'event' => 'api.server.deleted',
        'action' => 'deleted',
        'description' => 'Build server deleted',
        'resource_name' => 'Build server',
    ]);

    Livewire::test(AuditLog::class)
        ->set('search', 'Website')
        ->assertSee('Website created')
        ->assertDontSee('Build server deleted')
        ->set('search', '')
        ->set('action', 'deleted')
        ->assertDontSee('Website created')
        ->assertSee('Build server deleted');
});

test('updating team settings records an audit event', function () {
    Livewire::test(TeamIndex::class)
        ->set('name', 'Renamed team')
        ->call('submit')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'updated')->sole();

    expect($event->event)->toBe('ui.team.updated')
        ->and($event->team_id)->toBe($this->team->id)
        ->and($event->resource_name)->toBe('Renamed team');
});

test('updating an environment variable records an event without its value', function () {
    $variable = SharedEnvironmentVariable::create([
        'team_id' => $this->team->id,
        'type' => 'team',
        'key' => 'API_SECRET',
        'value' => 'old-secret',
    ]);

    Livewire::test(Show::class, [
        'env' => $variable,
        'type' => 'team',
    ])
        ->call('loadValues')
        ->set('value', 'new-secret')
        ->call('submit')
        ->assertHasNoErrors();

    $event = AuditEvent::query()
        ->where('resource_type', 'shared_environment_variable')
        ->where('action', 'updated')
        ->sole();

    expect($event->event)->toBe('ui.shared_environment_variable.updated')
        ->and($event->resource_name)->toBe('API_SECRET')
        ->and(json_encode($event->metadata))->not->toContain('new-secret');
});

test('creating an application environment variable records an audit event', function () {
    $this->withDefer();

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);

    $application->environment_variables()->create([
        'key' => 'API_SECRET',
        'value' => 'secret-value',
    ]);

    defer()->invoke();

    $event = AuditEvent::query()
        ->where('resource_type', 'environment_variable')
        ->where('action', 'created')
        ->where('resource_name', 'API_SECRET')
        ->firstOrFail();

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->resource_name)->toBe('API_SECRET')
        ->and(json_encode($event->metadata))->not->toContain('secret-value');
});

test('database cleanup removes audit events older than 90 days', function () {
    $old = AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'created_at' => now()->subDays(91),
    ]);
    $recent = AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'created_at' => now()->subDays(89),
    ]);

    AuditEvent::pruneExpired();

    expect($old->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull();
});
