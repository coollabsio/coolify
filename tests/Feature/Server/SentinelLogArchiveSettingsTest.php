<?php

use App\Actions\Server\StartSentinel;
use App\Livewire\Server\Sentinel;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'ip' => '10.0.0.10']);
});

it('sends the container log archive setting with the Sentinel default limits', function () {
    expect(StartSentinel::sentinelLogArchiveEnvironment($this->server->fresh()))->toBe([
        'LOG_ARCHIVE_ENABLED' => 'true',
        'LOG_ARCHIVE_MAX_FILE_MB' => '10',
        'LOG_ARCHIVE_MAX_TOTAL_MB' => '1024',
        'LOG_ARCHIVE_RETENTION_DAYS' => '7',
    ]);
});

it('saves the container log archive settings from the Sentinel page and restarts Sentinel', function () {
    Livewire::test(Sentinel::class, ['server' => $this->server->fresh()])
        ->set('logArchiveMaxFileMb', 25)
        ->set('logArchiveMaxTotalMb', 4096)
        ->set('logArchiveRetentionDays', 30)
        ->call('submit')
        ->assertHasNoErrors();

    expect(StartSentinel::sentinelLogArchiveEnvironment($this->server->fresh()))->toBe([
        'LOG_ARCHIVE_ENABLED' => 'true',
        'LOG_ARCHIVE_MAX_FILE_MB' => '25',
        'LOG_ARCHIVE_MAX_TOTAL_MB' => '4096',
        'LOG_ARCHIVE_RETENTION_DAYS' => '30',
    ]);
    expect(Queue::pushed(JobDecorator::class, fn (JobDecorator $job): bool => $job->getAction() instanceof StartSentinel))
        ->toHaveCount(1);
});

it('rejects container log archive limits out of range on the Sentinel page', function (string $property, int $value) {
    Livewire::test(Sentinel::class, ['server' => $this->server->fresh()])
        ->set($property, $value)
        ->call('submit')
        ->assertHasErrors($property);

    expect(StartSentinel::sentinelLogArchiveEnvironment($this->server->fresh())['LOG_ARCHIVE_MAX_TOTAL_MB'])->toBe('1024');
    expect(Queue::pushed(JobDecorator::class))->toHaveCount(0);
})->with([
    ['logArchiveMaxFileMb', 0],
    ['logArchiveMaxTotalMb', 102401],
    ['logArchiveRetentionDays', 366],
]);
