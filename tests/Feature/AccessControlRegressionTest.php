<?php

use App\Livewire\Tags\Deployments as TagDeployments;
use App\Livewire\Team\AdminView;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

test('user broadcast channel accepts only its own user ID', function () {
    $callback = Broadcast::getChannels()['user.{userId}'];
    $user = User::factory()->create();

    expect($callback($user, (int) $user->id))->toBeTrue()
        ->and($callback($user, (int) $user->id + 1))->toBeFalse();
});

test('team admin view checks instance admin access when it renders', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    $rootUser = User::factory()->create();
    $rootTeam->members()->attach($rootUser->id, ['role' => 'owner']);
    $this->actingAs($rootUser);
    session(['currentTeam' => $rootTeam]);

    Livewire::test(AdminView::class)->assertOk();

    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'admin']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    expect(fn () => (new AdminView)->render())->toThrow(HttpException::class);
});

test('tag deployments include only applications in the current team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $createDeployment = function (Team $owner, string $name): Application {
        $server = Server::factory()->create(['team_id' => $owner->id]);
        $project = Project::factory()->create(['team_id' => $owner->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $application = Application::factory()->create(['environment_id' => $environment->id]);
        ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $name,
            'deployment_uuid' => fake()->uuid(),
            'server_id' => $server->id,
            'server_name' => $server->name,
            'status' => 'queued',
        ]);

        return $application;
    };

    $owned = $createDeployment($team, 'Owned deployment');
    $foreign = $createDeployment(Team::factory()->create(), 'Foreign deployment');

    $component = Livewire::test(TagDeployments::class)
        ->set('resourceIds', [$owned->id, $foreign->id])
        ->call('getDeployments');
    $names = collect($component->get('deploymentsPerTagPerServer'))->flatten(1)->pluck('application_name');

    expect($names->all())->toContain('Owned deployment')
        ->not->toContain('Foreign deployment');
});
