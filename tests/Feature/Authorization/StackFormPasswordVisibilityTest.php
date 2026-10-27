<?php

use App\Livewire\Project\Service\StackForm;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->team->members()->attach($this->member, ['role' => 'member']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);

    $this->service = Service::factory()->create([
        'docker_compose_raw' => "services:\n  drizzle:\n    image: ghcr.io/drizzle-team/gateway:latest\n",
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $project->environments()->firstOrFail()->id,
    ]);
    ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $this->service->id,
        'name' => 'drizzle',
        'image' => 'ghcr.io/drizzle-team/gateway:latest',
    ]);
    $this->masterPassword = $this->service->environment_variables()->create([
        'key' => 'SERVICE_PASSWORD_DRIZZLE',
        'value' => 'drizzle-master-secret',
    ]);
});

function mountStackForm(): mixed
{
    return Livewire::test(StackForm::class, ['service' => test()->service]);
}

function expectNoMasterPassword(mixed $component): void
{
    expect($component->get('fields')['SERVICE_PASSWORD_DRIZZLE']['value'])->toBeNull()
        ->and(json_encode($component->snapshot).$component->html())->not->toContain('drizzle-master-secret');
}

it('hides extra-field passwords from a member after the session switches to a team they own', function () {
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($this->member, ['role' => 'owner']);
    $this->actingAs($this->member);
    session(['currentTeam' => $ownTeam]);

    expectNoMasterPassword(mountStackForm());
});

it('shows extra-field passwords to an owner of the service team', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(mountStackForm()->get('fields')['SERVICE_PASSWORD_DRIZZLE']['value'])->toBe('drizzle-master-secret');
});

it('hides locked extra-field passwords from an owner and keeps them on save', function () {
    $this->masterPassword->update(['is_shown_once' => true]);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $component = mountStackForm();
    expectNoMasterPassword($component);

    $component->set('fields.SERVICE_PASSWORD_DRIZZLE.isLocked', false)
        ->set('fields.SERVICE_PASSWORD_DRIZZLE.value', 'attacker-value')
        ->call('submit');

    expect($this->masterPassword->fresh()->value)->toBe('drizzle-master-secret');
});

it('hides an extra field that references a locked variable and keeps both on save', function () {
    $this->masterPassword->update(['value' => '$SERVICE_PASSWORD_64_DRIZZLE']);
    $lockedTarget = $this->service->environment_variables()->create([
        'key' => 'SERVICE_PASSWORD_64_DRIZZLE',
        'value' => 'referenced-locked-secret',
        'is_shown_once' => true,
    ]);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $component = mountStackForm();
    expect($component->get('fields')['SERVICE_PASSWORD_DRIZZLE']['value'])->toBeNull()
        ->and(json_encode($component->snapshot).$component->html())->not->toContain('referenced-locked-secret');

    $component->set('fields.SERVICE_PASSWORD_DRIZZLE.isLocked', false)
        ->set('fields.SERVICE_PASSWORD_DRIZZLE.value', 'attacker-value')
        ->call('submit');

    expect($this->masterPassword->fresh()->value)->toBe('$SERVICE_PASSWORD_64_DRIZZLE')
        ->and($lockedTarget->fresh()->value)->toBe('referenced-locked-secret');
});
