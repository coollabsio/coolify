<?php

use App\Models\Team;
use App\Models\User;
use App\Models\V5\Application as V5Application;
use App\Models\V5\Cluster;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Policies\V5\ApplicationPolicy;
use App\Policies\V5\ClusterPolicy;
use App\Policies\V5\ResourceConnectionPolicy;
use App\Policies\V5\ServerPolicy;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Build a user whose team membership carries the given role, so the policies'
 * $user->isAdminOfTeam() role gate can be exercised without touching the DB.
 */
function v5PolicyUser(string $role = 'owner', int $teamId = 10): User
{
    $user = (new User)->forceFill(['id' => 1]);
    $team = (new Team)->forceFill(['id' => $teamId]);
    $pivot = new Pivot;
    $pivot->role = $role;
    $team->setRelation('pivot', $pivot);
    $user->setRelation('teams', collect([$team]));

    return $user;
}

function v5PolicyMemberUser(int $teamId = 10): User
{
    return v5PolicyUser('member', $teamId);
}

function v5PolicyTeam(int $id = 10): Team
{
    return (new Team)->forceFill(['id' => $id]);
}

it('allows adding a server to a cluster of the current team', function () {
    $team = v5PolicyTeam();
    $cluster = (new Cluster)->forceFill(['id' => 5, 'team_id' => $team->id]);

    $response = (new ServerPolicy)->create(v5PolicyUser(), $team, $cluster);

    expect($response->allowed())->toBeTrue();
});

it('denies adding a server to another teams cluster as forbidden instead of not found', function () {
    $team = v5PolicyTeam(10);
    $foreignCluster = (new Cluster)->forceFill(['id' => 5, 'team_id' => 99]);

    $response = (new ServerPolicy)->create(v5PolicyUser(), $team, $foreignCluster);

    // A plain deny carries no status override, so the framework renders the
    // historical 403 instead of a 404.
    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull();
});

it('denies server update and delete outside the current team as not found', function () {
    $team = v5PolicyTeam(10);
    $foreignCluster = (new Cluster)->forceFill(['id' => 5, 'team_id' => 99]);
    $foreignServer = (new V5Server)->forceFill(['id' => 7, 'team_id' => 99, 'cluster_id' => 5]);

    $policy = new ServerPolicy;

    $update = $policy->update(v5PolicyUser(), $foreignServer, $team, $foreignCluster);
    $delete = $policy->delete(v5PolicyUser(), $foreignServer, $team, $foreignCluster);

    expect($update->denied())->toBeTrue()
        ->and($update->status())->toBe(404)
        ->and($delete->denied())->toBeTrue()
        ->and($delete->status())->toBe(404);
});

it('denies server update for a server outside the addressed cluster as not found', function () {
    $team = v5PolicyTeam(10);
    $cluster = (new Cluster)->forceFill(['id' => 5, 'team_id' => $team->id]);
    $serverInOtherCluster = (new V5Server)->forceFill(['id' => 7, 'team_id' => $team->id, 'cluster_id' => 6]);

    $response = (new ServerPolicy)->update(v5PolicyUser(), $serverInOtherCluster, $team, $cluster);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});

it('allows moving the caddy ingress canvas card only for ingress servers of the current team', function () {
    $team = v5PolicyTeam(10);
    $policy = new ServerPolicy;

    $ingressServer = (new V5Server)->forceFill(['id' => 7, 'team_id' => $team->id, 'is_ingress' => true]);

    expect($policy->updateCanvasPosition(v5PolicyUser(), $ingressServer, $team)->allowed())->toBeTrue();
});

it('denies moving the canvas card of a non-ingress server as not found', function () {
    $team = v5PolicyTeam(10);
    $nonIngressServer = (new V5Server)->forceFill(['id' => 7, 'team_id' => $team->id, 'is_ingress' => false]);

    $response = (new ServerPolicy)->updateCanvasPosition(v5PolicyUser(), $nonIngressServer, $team);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});

it('denies moving another teams ingress canvas card as not found', function () {
    $team = v5PolicyTeam(10);
    $foreignIngressServer = (new V5Server)->forceFill(['id' => 7, 'team_id' => 99, 'is_ingress' => true]);

    $response = (new ServerPolicy)->updateCanvasPosition(v5PolicyUser(), $foreignIngressServer, $team);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});

it('scopes cluster viewing and deletion to the current team with not-found denials', function () {
    $team = v5PolicyTeam(10);
    $policy = new ClusterPolicy;

    $ownCluster = (new Cluster)->forceFill(['id' => 5, 'team_id' => $team->id]);
    $foreignCluster = (new Cluster)->forceFill(['id' => 6, 'team_id' => 99]);

    expect($policy->view(v5PolicyUser(), $ownCluster, $team)->allowed())->toBeTrue()
        ->and($policy->delete(v5PolicyUser(), $ownCluster, $team)->allowed())->toBeTrue();

    $view = $policy->view(v5PolicyUser(), $foreignCluster, $team);
    $delete = $policy->delete(v5PolicyUser(), $foreignCluster, $team);

    expect($view->denied())->toBeTrue()
        ->and($view->status())->toBe(404)
        ->and($delete->denied())->toBeTrue()
        ->and($delete->status())->toBe(404);
});

it('scopes application mutations to the current team with not-found denials', function () {
    $team = v5PolicyTeam(10);
    $policy = new ApplicationPolicy;

    $ownApplication = (new V5Application)->forceFill(['id' => 3, 'team_id' => $team->id]);
    $foreignApplication = (new V5Application)->forceFill(['id' => 4, 'team_id' => 99]);

    expect($policy->update(v5PolicyUser(), $ownApplication, $team)->allowed())->toBeTrue()
        ->and($policy->updateIngress(v5PolicyUser(), $ownApplication, $team)->allowed())->toBeTrue()
        ->and($policy->delete(v5PolicyUser(), $ownApplication, $team)->allowed())->toBeTrue();

    foreach (['update', 'updateIngress', 'delete'] as $ability) {
        $response = $policy->{$ability}(v5PolicyUser(), $foreignApplication, $team);

        expect($response->denied())->toBeTrue()
            ->and($response->status())->toBe(404);
    }
});

it('scopes resource connection mutations to the current team with not-found denials', function () {
    $team = v5PolicyTeam(10);
    $policy = new ResourceConnectionPolicy;

    $ownConnection = (new ResourceConnection)->forceFill(['id' => 3, 'team_id' => $team->id]);
    $foreignConnection = (new ResourceConnection)->forceFill(['id' => 4, 'team_id' => 99]);

    expect($policy->update(v5PolicyUser(), $ownConnection, $team)->allowed())->toBeTrue()
        ->and($policy->delete(v5PolicyUser(), $ownConnection, $team)->allowed())->toBeTrue();

    foreach (['update', 'delete'] as $ability) {
        $response = $policy->{$ability}(v5PolicyUser(), $foreignConnection, $team);

        expect($response->denied())->toBeTrue()
            ->and($response->status())->toBe(404);
    }
});

it('denies server mutations for a member of the current team as forbidden not not-found', function () {
    $team = v5PolicyTeam(10);
    $cluster = (new Cluster)->forceFill(['id' => 5, 'team_id' => $team->id]);
    $server = (new V5Server)->forceFill(['id' => 7, 'team_id' => $team->id, 'cluster_id' => 5, 'is_ingress' => true]);
    $member = v5PolicyMemberUser();
    $policy = new ServerPolicy;

    expect($policy->create($member, $team, $cluster)->denied())->toBeTrue()
        ->and($policy->create($member, $team, $cluster)->status())->toBeNull();

    foreach (['update', 'delete', 'check', 'bootstrap'] as $ability) {
        $response = $policy->{$ability}($member, $server, $team, $cluster);

        expect($response->denied())->toBeTrue()
            ->and($response->status())->toBeNull();
    }

    expect($policy->updateCanvasPosition($member, $server, $team)->denied())->toBeTrue()
        ->and($policy->updateCanvasPosition($member, $server, $team)->status())->toBeNull();
});

it('still lets a member read server diagnostics of the current team', function () {
    $team = v5PolicyTeam(10);
    $cluster = (new Cluster)->forceFill(['id' => 5, 'team_id' => $team->id]);
    $server = (new V5Server)->forceFill(['id' => 7, 'team_id' => $team->id, 'cluster_id' => 5]);

    expect((new ServerPolicy)->viewDiagnostics(v5PolicyMemberUser(), $server, $team, $cluster)->allowed())->toBeTrue();
});

it('denies cluster create and delete for a member of the current team as forbidden', function () {
    $team = v5PolicyTeam(10);
    $cluster = (new Cluster)->forceFill(['id' => 5, 'team_id' => $team->id]);
    $member = v5PolicyMemberUser();
    $policy = new ClusterPolicy;

    expect($policy->create($member, $team)->denied())->toBeTrue()
        ->and($policy->create($member, $team)->status())->toBeNull()
        ->and($policy->delete($member, $cluster, $team)->denied())->toBeTrue()
        ->and($policy->delete($member, $cluster, $team)->status())->toBeNull();

    // Members may still read.
    expect($policy->view($member, $cluster, $team)->allowed())->toBeTrue();
});

it('allows an admin to create a cluster in the current team', function () {
    $team = v5PolicyTeam(10);

    expect((new ClusterPolicy)->create(v5PolicyUser('admin'), $team)->allowed())->toBeTrue();
});

it('denies application mutations for a member of the current team as forbidden', function () {
    $team = v5PolicyTeam(10);
    $application = (new V5Application)->forceFill(['id' => 3, 'team_id' => $team->id]);
    $member = v5PolicyMemberUser();
    $policy = new ApplicationPolicy;

    expect($policy->create($member, $team)->denied())->toBeTrue();

    foreach (['update', 'updateIngress', 'delete'] as $ability) {
        $response = $policy->{$ability}($member, $application, $team);

        expect($response->denied())->toBeTrue()
            ->and($response->status())->toBeNull();
    }
});

it('denies resource connection mutations for a member of the current team as forbidden', function () {
    $team = v5PolicyTeam(10);
    $connection = (new ResourceConnection)->forceFill(['id' => 3, 'team_id' => $team->id]);
    $member = v5PolicyMemberUser();
    $policy = new ResourceConnectionPolicy;

    expect($policy->create($member, $team)->denied())->toBeTrue();

    foreach (['update', 'delete'] as $ability) {
        $response = $policy->{$ability}($member, $connection, $team);

        expect($response->denied())->toBeTrue()
            ->and($response->status())->toBeNull();
    }
});

it('allows admins to create applications and resource connections', function () {
    $team = v5PolicyTeam(10);
    $admin = v5PolicyUser('admin');

    expect((new ApplicationPolicy)->create($admin, $team)->allowed())->toBeTrue()
        ->and((new ResourceConnectionPolicy)->create($admin, $team)->allowed())->toBeTrue();
});
