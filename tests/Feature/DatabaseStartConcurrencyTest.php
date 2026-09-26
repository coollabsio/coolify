<?php

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StopDatabase;
use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Events\DatabaseStatusChanged;
use App\Jobs\DatabaseStartJob;
use App\Livewire\Project\Database\Heading;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\ResourceStartActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('cache.default', 'array');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'exited',
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function concurrentStartActivity(string $databaseUuid, int $teamId, ProcessStatus $status = ProcessStatus::QUEUED, int $minutesSinceLastUpdate = 0): Activity
{
    $activity = activity()
        ->withProperties([
            'type' => ActivityTypes::INLINE->value,
            'type_uuid' => $databaseUuid,
            'status' => $status->value,
            'team_id' => $teamId,
            'operation' => ResourceStartActivity::DATABASE_START_OPERATION,
        ])
        ->event(ActivityTypes::INLINE->value)
        ->log('[]');

    Activity::query()->whereKey($activity->id)->update([
        'created_at' => now()->subMinutes($minutesSinceLastUpdate),
        'updated_at' => now()->subMinutes($minutesSinceLastUpdate),
    ]);

    return $activity->refresh();
}

function databaseStartActivityCount(string $databaseUuid): int
{
    return Activity::query()
        ->where('properties->type_uuid', $databaseUuid)
        ->where('properties->operation', ResourceStartActivity::DATABASE_START_OPERATION)
        ->count();
}

function databaseStartApiHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'];
}

describe('start and restart guards', function () {
    it('does not queue a second start from the UI while a start is queued', function () {
        Bus::fake();
        concurrentStartActivity($this->database->uuid, $this->team->id);

        Livewire::actingAs($this->user)->test(Heading::class, ['database' => $this->database])
            ->call('start')
            ->assertDispatched('error', ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);

        Bus::assertNotDispatched(DatabaseStartJob::class);
        expect(databaseStartActivityCount($this->database->uuid))->toBe(1);
    });

    it('does not stop the database on a UI restart while a start is in progress', function () {
        Bus::fake();
        StopDatabase::shouldRun()->never();
        concurrentStartActivity($this->database->uuid, $this->team->id, ProcessStatus::IN_PROGRESS);

        Livewire::actingAs($this->user)->test(Heading::class, ['database' => $this->database])
            ->call('restart')
            ->assertDispatched('error', ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);

        Bus::assertNotDispatched(DatabaseStartJob::class);
        expect(databaseStartActivityCount($this->database->uuid))->toBe(1);
    });

    it('rejects an API start while a start is queued', function () {
        Queue::fake();
        concurrentStartActivity($this->database->uuid, $this->team->id);

        $this->withHeaders(databaseStartApiHeaders($this->bearerToken))
            ->postJson("/api/v1/databases/{$this->database->uuid}/start")
            ->assertStatus(409)
            ->assertJson(['message' => ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE]);

        StartDatabase::assertNotPushed();
    });

    it('rejects an API restart while a start is queued', function () {
        Queue::fake();
        concurrentStartActivity($this->database->uuid, $this->team->id);

        $this->withHeaders(databaseStartApiHeaders($this->bearerToken))
            ->postJson("/api/v1/databases/{$this->database->uuid}/restart")
            ->assertStatus(409)
            ->assertJson(['message' => ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE]);

        RestartDatabase::assertNotPushed();
    });

    it('still queues an API start when no start is running', function () {
        Queue::fake();
        concurrentStartActivity($this->database->uuid, $this->team->id, ProcessStatus::FINISHED);

        $this->withHeaders(databaseStartApiHeaders($this->bearerToken))
            ->postJson("/api/v1/databases/{$this->database->uuid}/start")
            ->assertOk();

        StartDatabase::assertPushed();
    });

    it('does not reveal a running start of another team database through the API', function () {
        Queue::fake();
        concurrentStartActivity($this->database->uuid, $this->team->id);
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser, ['role' => 'owner']);
        session(['currentTeam' => $otherTeam]);
        $otherToken = $otherUser->createToken('other-token', ['*'])->plainTextToken;

        $this->withHeaders(databaseStartApiHeaders($otherToken))
            ->postJson("/api/v1/databases/{$this->database->uuid}/start")
            ->assertNotFound();

        StartDatabase::assertNotPushed();
    });

    it('refuses to queue a second start in the action itself', function () {
        Bus::fake();
        concurrentStartActivity($this->database->uuid, $this->team->id);

        expect(StartDatabase::run($this->database))->toBe(ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);

        Bus::assertNotDispatched(DatabaseStartJob::class);
        expect(databaseStartActivityCount($this->database->uuid))->toBe(1);
    });

    it('does not stop the database when a queued restart action finds a running start', function () {
        Bus::fake();
        StopDatabase::shouldRun()->never();
        concurrentStartActivity($this->database->uuid, $this->team->id, ProcessStatus::IN_PROGRESS);

        expect(RestartDatabase::run($this->database))->toBe(ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);
    });

    it('lets a new start replace a stale queued start and marks the stale one as failed', function () {
        Bus::fake();
        $stale = concurrentStartActivity($this->database->uuid, $this->team->id, minutesSinceLastUpdate: 11);

        expect(StartDatabase::run($this->database))->toBeInstanceOf(Activity::class);

        Bus::assertDispatched(DatabaseStartJob::class);
        $stale->refresh();
        expect(data_get($stale, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
            ->and(data_get($stale, 'properties.error'))->toBe(ResourceStartActivity::STALE_MESSAGE);
    });
});

describe('start job', function () {
    beforeEach(function () {
        Event::fake([DatabaseStatusChanged::class]);
    });

    it('does not run the start commands while another start of the same database holds the lock', function () {
        StartPostgresql::shouldRun()->never();
        $activity = concurrentStartActivity($this->database->uuid, $this->team->id);
        $lock = Cache::lock(ResourceStartActivity::databaseStartLockKey($this->database->uuid), 60);
        expect($lock->get())->toBeTrue();

        try {
            DatabaseStartJob::dispatchSync(StandalonePostgresql::class, $this->database->id, $this->team->id, $activity->id, null);
        } finally {
            $lock->release();
        }

        $activity->refresh();
        expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
            ->and(data_get($activity, 'properties.error'))->toBe(ResourceStartActivity::ALREADY_STARTING_MESSAGE);
    });

    it('holds the start lock while the start commands run and releases it afterwards', function () {
        $activity = concurrentStartActivity($this->database->uuid, $this->team->id);
        $lockKey = ResourceStartActivity::databaseStartLockKey($this->database->uuid);
        $lockWasHeld = null;
        StartPostgresql::shouldRun()->once()->andReturnUsing(function ($database, Activity $activity) use ($lockKey, &$lockWasHeld) {
            $probe = Cache::lock($lockKey, 60);
            $lockWasHeld = ! $probe->get();
            if (! $lockWasHeld) {
                $probe->release();
            }
            $activity->properties = $activity->properties->merge(['status' => ProcessStatus::FINISHED->value]);
            $activity->save();

            return $activity;
        });

        DatabaseStartJob::dispatchSync(StandalonePostgresql::class, $this->database->id, $this->team->id, $activity->id, null);

        expect($lockWasHeld)->toBeTrue();
        $lock = Cache::lock($lockKey, 60);
        expect($lock->get())->toBeTrue();
        $lock->release();
    });

    it('skips a start that a newer start of the same database replaced', function () {
        StartPostgresql::shouldRun()->never();
        $older = concurrentStartActivity($this->database->uuid, $this->team->id, minutesSinceLastUpdate: 20);
        concurrentStartActivity($this->database->uuid, $this->team->id);

        DatabaseStartJob::dispatchSync(StandalonePostgresql::class, $this->database->id, $this->team->id, $older->id, null);

        $older->refresh();
        expect(data_get($older, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
            ->and(data_get($older, 'properties.error'))->toBe(ResourceStartActivity::SUPERSEDED_MESSAGE);
    });

    it('skips a start whose activity is no longer queued', function () {
        StartPostgresql::shouldRun()->never();
        $activity = concurrentStartActivity($this->database->uuid, $this->team->id, ProcessStatus::ERROR);

        DatabaseStartJob::dispatchSync(StandalonePostgresql::class, $this->database->id, $this->team->id, $activity->id, null);

        expect(data_get($activity->refresh(), 'properties.status'))->toBe(ProcessStatus::ERROR->value);
    });
});
