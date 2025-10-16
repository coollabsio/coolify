<?php

use App\Actions\User\DeleteUserResources;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    // Mock user
    $this->user = Mockery::mock(User::class);
    $this->user->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $this->user->shouldReceive('getAttribute')->with('email')->andReturn('test@example.com');
});

afterEach(function () {
    Mockery::close();
});

it('only collects resources from teams where user is the sole member', function () {
    // Mock owned team where user is the ONLY member (will be deleted)
    $ownedTeamPivot = (object) ['role' => 'owner'];
    $ownedTeam = Mockery::mock(Team::class);
    $ownedTeam->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $ownedTeam->shouldReceive('getAttribute')->with('pivot')->andReturn($ownedTeamPivot);
    $ownedTeam->shouldReceive('getAttribute')->with('members')->andReturn(collect([$this->user]));
    $ownedTeam->shouldReceive('setAttribute')->andReturnSelf();
    $ownedTeam->pivot = $ownedTeamPivot;
    $ownedTeam->members = collect([$this->user]);

    // Mock member team (user is NOT owner)
    $memberTeamPivot = (object) ['role' => 'member'];
    $memberTeam = Mockery::mock(Team::class);
    $memberTeam->shouldReceive('getAttribute')->with('id')->andReturn(2);
    $memberTeam->shouldReceive('getAttribute')->with('pivot')->andReturn($memberTeamPivot);
    $memberTeam->shouldReceive('getAttribute')->with('members')->andReturn(collect([$this->user]));
    $memberTeam->shouldReceive('setAttribute')->andReturnSelf();
    $memberTeam->pivot = $memberTeamPivot;
    $memberTeam->members = collect([$this->user]);

    // Mock servers for owned team
    $ownedServer = Mockery::mock(Server::class);
    $ownedServer->shouldReceive('applications')->andReturn(collect([
        (object) ['id' => 1, 'name' => 'app1'],
    ]));
    $ownedServer->shouldReceive('databases')->andReturn(collect([
        (object) ['id' => 1, 'name' => 'db1'],
    ]));
    $ownedServer->shouldReceive('services->get')->andReturn(collect([
        (object) ['id' => 1, 'name' => 'service1'],
    ]));

    // Mock teams relationship
    $teamsRelation = Mockery::mock();
    $teamsRelation->shouldReceive('get')->andReturn(collect([$ownedTeam, $memberTeam]));
    $this->user->shouldReceive('teams')->andReturn($teamsRelation);

    // Mock servers relationship for owned team
    $ownedServersRelation = Mockery::mock();
    $ownedServersRelation->shouldReceive('get')->andReturn(collect([$ownedServer]));
    $ownedTeam->shouldReceive('servers')->andReturn($ownedServersRelation);

    // Execute
    $action = new DeleteUserResources($this->user, true);
    $preview = $action->getResourcesPreview();

    // Assert: Should only include resources from owned team where user is sole member
    expect($preview['applications'])->toHaveCount(1);
    expect($preview['applications']->first()->id)->toBe(1);
    expect($preview['applications']->first()->name)->toBe('app1');

    expect($preview['databases'])->toHaveCount(1);
    expect($preview['databases']->first()->id)->toBe(1);

    expect($preview['services'])->toHaveCount(1);
    expect($preview['services']->first()->id)->toBe(1);
});

it('does not collect resources when user is owner but team has other members', function () {
    // Mock owned team with multiple members (will be transferred, not deleted)
    $otherUser = Mockery::mock(User::class);
    $otherUser->shouldReceive('getAttribute')->with('id')->andReturn(2);

    $ownedTeamPivot = (object) ['role' => 'owner'];
    $ownedTeam = Mockery::mock(Team::class);
    $ownedTeam->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $ownedTeam->shouldReceive('getAttribute')->with('pivot')->andReturn($ownedTeamPivot);
    $ownedTeam->shouldReceive('getAttribute')->with('members')->andReturn(collect([$this->user, $otherUser]));
    $ownedTeam->shouldReceive('setAttribute')->andReturnSelf();
    $ownedTeam->pivot = $ownedTeamPivot;
    $ownedTeam->members = collect([$this->user, $otherUser]);

    // Mock teams relationship
    $teamsRelation = Mockery::mock();
    $teamsRelation->shouldReceive('get')->andReturn(collect([$ownedTeam]));
    $this->user->shouldReceive('teams')->andReturn($teamsRelation);

    // Execute
    $action = new DeleteUserResources($this->user, true);
    $preview = $action->getResourcesPreview();

    // Assert: Should have no resources (team will be transferred, not deleted)
    expect($preview['applications'])->toBeEmpty();
    expect($preview['databases'])->toBeEmpty();
    expect($preview['services'])->toBeEmpty();
});

it('does not collect resources when user is only a member of teams', function () {
    // Mock member team (user is NOT owner)
    $memberTeamPivot = (object) ['role' => 'member'];
    $memberTeam = Mockery::mock(Team::class);
    $memberTeam->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $memberTeam->shouldReceive('getAttribute')->with('pivot')->andReturn($memberTeamPivot);
    $memberTeam->shouldReceive('getAttribute')->with('members')->andReturn(collect([$this->user]));
    $memberTeam->shouldReceive('setAttribute')->andReturnSelf();
    $memberTeam->pivot = $memberTeamPivot;
    $memberTeam->members = collect([$this->user]);

    // Mock teams relationship
    $teamsRelation = Mockery::mock();
    $teamsRelation->shouldReceive('get')->andReturn(collect([$memberTeam]));
    $this->user->shouldReceive('teams')->andReturn($teamsRelation);

    // Execute
    $action = new DeleteUserResources($this->user, true);
    $preview = $action->getResourcesPreview();

    // Assert: Should have no resources
    expect($preview['applications'])->toBeEmpty();
    expect($preview['databases'])->toBeEmpty();
    expect($preview['services'])->toBeEmpty();
});

it('collects resources only from teams where user is sole member', function () {
    // Mock first team: user is sole member (will be deleted)
    $ownedTeam1Pivot = (object) ['role' => 'owner'];
    $ownedTeam1 = Mockery::mock(Team::class);
    $ownedTeam1->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $ownedTeam1->shouldReceive('getAttribute')->with('pivot')->andReturn($ownedTeam1Pivot);
    $ownedTeam1->shouldReceive('getAttribute')->with('members')->andReturn(collect([$this->user]));
    $ownedTeam1->shouldReceive('setAttribute')->andReturnSelf();
    $ownedTeam1->pivot = $ownedTeam1Pivot;
    $ownedTeam1->members = collect([$this->user]);

    // Mock second team: user is owner but has other members (will be transferred)
    $otherUser = Mockery::mock(User::class);
    $otherUser->shouldReceive('getAttribute')->with('id')->andReturn(2);

    $ownedTeam2Pivot = (object) ['role' => 'owner'];
    $ownedTeam2 = Mockery::mock(Team::class);
    $ownedTeam2->shouldReceive('getAttribute')->with('id')->andReturn(2);
    $ownedTeam2->shouldReceive('getAttribute')->with('pivot')->andReturn($ownedTeam2Pivot);
    $ownedTeam2->shouldReceive('getAttribute')->with('members')->andReturn(collect([$this->user, $otherUser]));
    $ownedTeam2->shouldReceive('setAttribute')->andReturnSelf();
    $ownedTeam2->pivot = $ownedTeam2Pivot;
    $ownedTeam2->members = collect([$this->user, $otherUser]);

    // Mock server for team 1 (sole member - will be deleted)
    $server1 = Mockery::mock(Server::class);
    $server1->shouldReceive('applications')->andReturn(collect([
        (object) ['id' => 1, 'name' => 'app1'],
    ]));
    $server1->shouldReceive('databases')->andReturn(collect([]));
    $server1->shouldReceive('services->get')->andReturn(collect([]));

    // Mock teams relationship
    $teamsRelation = Mockery::mock();
    $teamsRelation->shouldReceive('get')->andReturn(collect([$ownedTeam1, $ownedTeam2]));
    $this->user->shouldReceive('teams')->andReturn($teamsRelation);

    // Mock servers for team 1
    $servers1Relation = Mockery::mock();
    $servers1Relation->shouldReceive('get')->andReturn(collect([$server1]));
    $ownedTeam1->shouldReceive('servers')->andReturn($servers1Relation);

    // Execute
    $action = new DeleteUserResources($this->user, true);
    $preview = $action->getResourcesPreview();

    // Assert: Should only include resources from team 1 (sole member)
    expect($preview['applications'])->toHaveCount(1);
    expect($preview['applications']->first()->id)->toBe(1);
});
