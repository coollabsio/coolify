<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Application\HealthChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = $this->server->standaloneDockers()->firstOrCreate(
            ['network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'test-project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

describe('HealthChanged notification', function () {
    test('can be constructed for unhealthy status', function () {
        $notification = new HealthChanged($this->application, 'running:unhealthy');

        expect($notification->isHealthy)->toBeFalse();
        expect($notification->newStatus)->toBe('running:unhealthy');
        expect($notification->resource_name)->toBe($this->application->name);
    });

    test('can be constructed for healthy status', function () {
        $notification = new HealthChanged($this->application, 'running:healthy');

        expect($notification->isHealthy)->toBeTrue();
        expect($notification->newStatus)->toBe('running:healthy');
    });

    test('toDiscord returns error color when unhealthy', function () {
        $notification = new HealthChanged($this->application, 'running:unhealthy');
        $discord = $notification->toDiscord();

        expect($discord->title)->toContain('unhealthy');
        expect($discord->isCritical)->toBeTrue();
    });

    test('toDiscord returns success color when healthy', function () {
        $notification = new HealthChanged($this->application, 'running:healthy');
        $discord = $notification->toDiscord();

        expect($discord->title)->toContain('healthy');
        expect($discord->isCritical)->toBeFalse();
    });

    test('toWebhook includes correct event type', function () {
        $notification = new HealthChanged($this->application, 'running:unhealthy');
        $webhook = $notification->toWebhook();

        expect($webhook['event'])->toBe('health_changed');
        expect($webhook['status'])->toBe('unhealthy');
        expect($webhook['success'])->toBeFalse();
        expect($webhook['application_uuid'])->toBe($this->application->uuid);
    });

    test('toWebhook marks success true when healthy', function () {
        $notification = new HealthChanged($this->application, 'running:healthy');
        $webhook = $notification->toWebhook();

        expect($webhook['success'])->toBeTrue();
        expect($webhook['status'])->toBe('healthy');
    });

    test('toMail uses correct subject when unhealthy', function () {
        $notification = new HealthChanged($this->application, 'running:unhealthy');
        $mail = $notification->toMail();

        expect($mail->subject)->toContain('unhealthy');
        expect($mail->subject)->toContain($this->application->name);
    });

    test('toMail uses correct subject when healthy', function () {
        $notification = new HealthChanged($this->application, 'running:healthy');
        $mail = $notification->toMail();

        expect($mail->subject)->toContain('healthy');
    });

    test('resource_url points to correct application path', function () {
        $notification = new HealthChanged($this->application, 'running:unhealthy');

        expect($notification->resource_url)->toContain($this->application->uuid);
        expect($notification->resource_url)->toContain($this->project->uuid);
    });
});

describe('Health status transition detection', function () {
    test('healthy to unhealthy is a health change', function () {
        $statusFromDb = 'running:healthy';
        $aggregatedStatus = 'running:unhealthy';

        $wasHealthy = str($statusFromDb)->contains(':healthy');
        $isUnhealthy = str($aggregatedStatus)->contains(':unhealthy');

        expect($wasHealthy && $isUnhealthy)->toBeTrue();
    });

    test('unhealthy to healthy is a health change', function () {
        $statusFromDb = 'running:unhealthy';
        $aggregatedStatus = 'running:healthy';

        $wasUnhealthy = str($statusFromDb)->contains(':unhealthy');
        $isHealthy = str($aggregatedStatus)->contains(':healthy');

        expect($wasUnhealthy && $isHealthy)->toBeTrue();
    });

    test('healthy to exited is not a health change', function () {
        $statusFromDb = 'running:healthy';
        $aggregatedStatus = 'exited';

        $wasHealthy = str($statusFromDb)->contains(':healthy');
        $isUnhealthy = str($aggregatedStatus)->contains(':unhealthy');
        $wasUnhealthy = str($statusFromDb)->contains(':unhealthy');
        $isHealthy = str($aggregatedStatus)->contains(':healthy');

        $isHealthChange = ($wasHealthy && $isUnhealthy) || ($wasUnhealthy && $isHealthy);
        expect($isHealthChange)->toBeFalse();
    });

    test('healthy to healthy (same status) is not a change', function () {
        $statusFromDb = 'running:healthy';
        $aggregatedStatus = 'running:healthy';

        // No change — status is identical, so the update block is never entered
        expect($statusFromDb === $aggregatedStatus)->toBeTrue();
    });

    test('team is notified when status changes from healthy to unhealthy', function () {
        Notification::fake();

        $this->team->notify(new HealthChanged($this->application, 'running:unhealthy'));

        Notification::assertSentTo($this->team, HealthChanged::class, function ($notification) {
            return $notification->newStatus === 'running:unhealthy'
                && $notification->isHealthy === false;
        });
    });

    test('team is notified when status recovers to healthy', function () {
        Notification::fake();

        $this->team->notify(new HealthChanged($this->application, 'running:healthy'));

        Notification::assertSentTo($this->team, HealthChanged::class, function ($notification) {
            return $notification->newStatus === 'running:healthy'
                && $notification->isHealthy === true;
        });
    });
});
