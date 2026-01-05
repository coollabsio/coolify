<?php

use App\Models\User;
use App\Policies\PrivateKeyPolicy;

it('allows root team admin to view system private key', function () {
    $teams = collect([
        (object) ['id' => 0, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 0;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeTrue();
});

it('allows root team owner to view system private key', function () {
    $teams = collect([
        (object) ['id' => 0, 'pivot' => (object) ['role' => 'owner']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 0;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeTrue();
});

it('denies regular member of root team to view system private key', function () {
    $teams = collect([
        (object) ['id' => 0, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 0;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeFalse();
});

it('denies non-root team member to view system private key', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'owner']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 0;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeFalse();
});

it('allows team member to view their own team private key', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 1;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeTrue();
});

it('denies team member to view another team private key', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'owner']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 2;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeFalse();
});

it('allows root team admin to update system private key', function () {
    $teams = collect([
        (object) ['id' => 0, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 0;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->update($user, $privateKey))->toBeTrue();
});

it('denies root team member to update system private key', function () {
    $teams = collect([
        (object) ['id' => 0, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 0;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->update($user, $privateKey))->toBeFalse();
});

it('allows team admin to update their own team private key', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 1;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->update($user, $privateKey))->toBeTrue();
});

it('denies team member to update their own team private key', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 1;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->update($user, $privateKey))->toBeFalse();
});

it('allows root team admin to delete system private key', function () {
    $teams = collect([
        (object) ['id' => 0, 'pivot' => (object) ['role' => 'admin']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 0;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->delete($user, $privateKey))->toBeTrue();
});

it('denies root team member to delete system private key', function () {
    $teams = collect([
        (object) ['id' => 0, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $privateKey = new class
    {
        public $team_id = 0;
    };

    $policy = new PrivateKeyPolicy;
    expect($policy->delete($user, $privateKey))->toBeFalse();
});
