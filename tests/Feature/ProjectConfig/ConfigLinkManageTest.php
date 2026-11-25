<?php

use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\User;
use App\Services\ProjectConfig\ConfigLinks;
use App\Services\ProjectConfig\ManagedResources;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'branch' => 'main']);
    $this->token = $this->fx['user']->createToken('all', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
});

function manageMember(object $test): User
{
    $member = User::factory()->create();
    $test->fx['team']->members()->attach($member->id, ['role' => 'member']);

    return $member;
}

it('disconnects a link and makes its resources editable again', function () {
    $app = ManagedResources::whileApplying(fn () => Application::factory()->create([
        'name' => 'api', 'config_key' => 'api', 'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]));
    expect(ManagedResources::isManaged($app->fresh()))->toBeTrue();

    app(ConfigLinks::class)->disconnect($this->link, $this->fx['user']);

    expect(EnvironmentConfigLink::count())->toBe(0)
        ->and(ManagedResources::isManaged($app->fresh()))->toBeFalse()
        ->and($app->fresh()->config_key)->toBe('api');
});

it('changes automatic apply', function () {
    app(ConfigLinks::class)->setAutoApply($this->link, $this->fx['user'], false);

    expect($this->link->fresh()->auto_apply)->toBeFalse();
});

it('lets only a team admin disconnect or change automatic apply', function () {
    $member = manageMember($this);

    expect(fn () => app(ConfigLinks::class)->disconnect($this->link, $member))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ConfigLinks::class)->setAutoApply($this->link, $member, false))->toThrow(AuthorizationException::class)
        ->and(EnvironmentConfigLink::count())->toBe(1);
});

it('disconnects and changes automatic apply through the API', function () {
    $headers = ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];

    $this->withHeaders($headers)->patchJson("/api/v1/config/links/{$this->link->uuid}", ['auto_apply' => false])->assertOk()->assertJsonFragment(['auto_apply' => false]);
    app('auth')->forgetGuards();
    $this->withHeaders($headers)->deleteJson("/api/v1/config/links/{$this->link->uuid}")->assertOk();

    expect(EnvironmentConfigLink::count())->toBe(0);
});
