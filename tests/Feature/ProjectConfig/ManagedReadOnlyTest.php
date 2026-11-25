<?php

use App\Jobs\VolumeBackupJob;
use App\Livewire\Project\Application\General;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentVariable;
use App\Models\LocalPersistentVolume;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\ProjectConfig\ManagedResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'branch' => 'main', 'file_path' => 'coolify.json']);
    $this->managed = ManagedResources::whileApplying(fn () => Application::factory()->create([
        'name' => 'api', 'config_key' => 'api', 'ports_exposes' => '3000', 'static_image' => 'nginx:alpine',
        'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]));
    $this->token = $this->fx['user']->createToken('all', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
});

function readOnlyEnv(Application $app, string $key, string $form, string $value = 'x'): EnvironmentVariable
{
    return ManagedResources::whileApplying(fn () => EnvironmentVariable::create([
        'key' => $key, 'value' => $value, 'config_meta' => ['form' => $form],
        'resourceable_type' => $app->getMorphClass(), 'resourceable_id' => $app->id,
    ]));
}

it('denies updates of a managed resource with a reason, and allows deploy', function () {
    $response = Gate::forUser($this->fx['user'])->inspect('update', $this->managed);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toContain('coolify.json on main')
        ->and(Gate::forUser($this->fx['user'])->allows('deploy', $this->managed))->toBeTrue();
});

it('allows updates of an unkeyed resource in a linked environment', function () {
    $this->managed->forceFill(['config_key' => null])->save();

    expect(Gate::forUser($this->fx['user'])->allows('update', $this->managed->fresh()))->toBeTrue();
});

it('blocks the General page save', function () {
    $this->actingAs($this->fx['user']);

    Livewire::test(General::class, ['application' => $this->managed->fresh()])
        ->set('portsExposes', '9000')
        ->call('submit');

    expect($this->managed->fresh()->ports_exposes)->toBe('3000');
});

it('blocks the API update', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])
        ->patchJson("/api/v1/applications/{$this->managed->uuid}", ['ports_exposes' => '9000'])
        ->assertForbidden();

    expect($this->managed->fresh()->ports_exposes)->toBe('3000');
});

it('lets a required value change, and nothing else of a managed variable', function () {
    $required = readOnlyEnv($this->managed, 'STRIPE_KEY', 'required', '');
    $plain = readOnlyEnv($this->managed, 'NODE_ENV', 'value', 'production');

    $required->update(['value' => 'sk_live']);
    expect($required->fresh()->value)->toBe('sk_live');

    expect(fn () => $plain->update(['value' => 'dev']))->toThrow(ValidationException::class)
        ->and(fn () => $required->update(['is_buildtime' => false]))->toThrow(ValidationException::class)
        ->and(fn () => $plain->delete())->toThrow(ValidationException::class)
        ->and(fn () => EnvironmentVariable::create(['key' => 'NEW', 'value' => '1', 'resourceable_type' => $this->managed->getMorphClass(), 'resourceable_id' => $this->managed->id]))->toThrow(ValidationException::class);
});

it('sets a required value through the API but not a managed value', function () {
    readOnlyEnv($this->managed, 'STRIPE_KEY', 'required', '');
    readOnlyEnv($this->managed, 'NODE_ENV', 'value', 'production');
    $headers = ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];

    $this->withHeaders($headers)->patchJson("/api/v1/applications/{$this->managed->uuid}/envs", ['key' => 'STRIPE_KEY', 'value' => 'sk_live'])->assertSuccessful();
    $this->withHeaders($headers)->patchJson("/api/v1/applications/{$this->managed->uuid}/envs", ['key' => 'NODE_ENV', 'value' => 'dev'])->assertStatus(422);

    expect(EnvironmentVariable::where('key', 'NODE_ENV')->where('is_preview', false)->value('value'))->toBe('production');
});

it('opens edits again while the link is suspended', function () {
    $this->link->update(['suspended_at' => now()]);
    $plain = readOnlyEnv($this->managed, 'NODE_ENV', 'value', 'production');

    $plain->update(['value' => 'dev']);

    expect(Gate::forUser($this->fx['user'])->allows('update', $this->managed->fresh()))->toBeTrue()
        ->and($plain->fresh()->value)->toBe('dev');
});

it('still denies after the user switches to another team they own', function () {
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'admin']);
    $own = Team::factory()->create();
    $own->members()->attach($member->id, ['role' => 'owner']);
    $this->actingAs($member);
    session(['currentTeam' => $this->fx['team']]);

    $component = Livewire::test(General::class, ['application' => $this->managed->fresh()]);
    session(['currentTeam' => $own]);
    $component->set('portsExposes', '9000')->call('submit');

    expect($this->managed->fresh()->ports_exposes)->toBe('3000');
});

it('looks up the link of an environment once, and sees link changes', function () {
    $other = $this->managed->replicate(['uuid', 'config_key'])->forceFill(['uuid' => new_public_id(), 'config_key' => 'web']);
    ManagedResources::whileApplying(fn () => $other->save());

    DB::enableQueryLog();
    $managed = collect(range(1, 5))->map(fn () => ManagedResources::isManaged($this->managed) && ManagedResources::isManaged($other))->unique()->all();
    $linkQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'environment_config_links'))->count();
    DB::disableQueryLog();

    expect($managed)->toBe([true])->and($linkQueries)->toBe(1);

    $this->link->update(['suspended_at' => now()]);
    expect(ManagedResources::isManaged($this->managed))->toBeFalse();

    $this->link->update(['suspended_at' => null]);
    expect(ManagedResources::isManaged($this->managed))->toBeTrue();

    $this->link->delete();
    expect(ManagedResources::isManaged($this->managed))->toBeFalse();

    EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);
    expect(ManagedResources::isManaged($this->managed))->toBeTrue();
});

it('still shows commands of a managed resource to a team admin, but not to a member', function () {
    $this->managed->update(['install_command' => 'npm ci && echo marker9731']);
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);

    expect(Gate::forUser($this->fx['user'])->allows('update', $this->managed))->toBeFalse()
        ->and(ManagedResources::canUpdateIgnoringFile($this->fx['user'], $this->managed))->toBeTrue()
        ->and(ManagedResources::canUpdateIgnoringFile($member, $this->managed))->toBeFalse()
        ->and(ManagedResources::isManaged($this->managed))->toBeTrue();

    $this->actingAs($this->fx['user']);
    Livewire::test(General::class, ['application' => $this->managed->fresh()])->assertSet('installCommand', 'npm ci && echo marker9731');
    $this->actingAs($member);
    Livewire::test(General::class, ['application' => $this->managed->fresh()])->assertSet('installCommand', null);
});

function managedVolume($resource): LocalPersistentVolume
{
    return ManagedResources::whileApplying(fn () => LocalPersistentVolume::create([
        'name' => 'data', 'mount_path' => '/data',
        'resource_id' => $resource->id, 'resource_type' => $resource->getMorphClass(),
    ]));
}

it('schedules and runs storage backups of a managed application through the API', function () {
    Queue::fake();
    $volume = managedVolume($this->managed);
    $headers = ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];

    $this->withHeaders($headers)
        ->putJson("/api/v1/applications/{$this->managed->uuid}/storages/{$volume->uuid}/backups", ['frequency' => '0 2 * * *'])
        ->assertCreated();
    $this->withHeaders($headers)
        ->postJson("/api/v1/applications/{$this->managed->uuid}/storages/{$volume->uuid}/backups/run")
        ->assertSuccessful();

    expect(ScheduledVolumeBackup::query()->sole()->frequency)->toBe('0 2 * * *');
    Queue::assertPushed(VolumeBackupJob::class);
});

it('saves and runs storage backups of a managed application in the dashboard', function () {
    Queue::fake();
    $volume = managedVolume($this->managed);
    $this->actingAs($this->fx['user']);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $this->managed->fresh()])
        ->set('frequency', 'hourly')
        ->call('save')
        ->assertHasNoErrors()
        ->call('backupNow');

    expect(ScheduledVolumeBackup::query()->sole()->frequency)->toBe('hourly');
    Queue::assertPushed(VolumeBackupJob::class);
});

it('lets team admins but not members manage backups of a managed service', function () {
    $service = ManagedResources::whileApplying(fn () => Service::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'server_id' => $this->fx['server']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
        'config_key' => 'web', 'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]));
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);

    expect(Gate::forUser($this->fx['user'])->allows('update', $service))->toBeFalse()
        ->and(Gate::forUser($this->fx['user'])->allows('manageBackups', $service))->toBeTrue()
        ->and(Gate::forUser($member)->allows('manageBackups', $service))->toBeFalse()
        ->and(Gate::forUser($member)->allows('manageBackups', $this->managed))->toBeFalse();
});
