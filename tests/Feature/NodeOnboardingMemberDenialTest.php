<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\InspectNodeHost;
use App\Actions\Node\ValidateNodeCallback;
use App\Jobs\OnboardNodeJob;
use App\Livewire\Node\Onboarding;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('app.url', 'https://coolify.example.com');
    config()->set('constants.sentinel.host_enabled', true);
    Queue::fake();
    Process::fake();
    InspectNodeHost::shouldRun()->never();
    ValidateNodeCallback::shouldRun()->never();
    AssignNodeToCluster::shouldRun()->never();
    CreateNodeCluster::shouldRun()->never();

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function demoteOnboardingUserToMember(): void
{
    test()->team->members()->updateExistingPivot(test()->user->id, ['role' => 'member']);
    test()->user->unsetRelation('teams');
}

it('does not let members open the onboarding wizard', function () {
    demoteOnboardingUserToMember();

    Livewire::test(Onboarding::class)->assertForbidden();
});

it('does not let members resume the onboarding of an existing server', function () {
    $node = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id, 'metadata' => ['onboarding' => ['status' => 'review']]]);
    demoteOnboardingUserToMember();

    Livewire::withQueryParams(['server' => $node->uuid])->test(Onboarding::class)->assertForbidden();
});

it('refuses to connect a server after the user lost the admin role', function () {
    $component = Livewire::test(Onboarding::class)
        ->set('name', 'Worker')->set('ip', '192.0.2.50')->set('privateKeyId', $this->key->id);
    demoteOnboardingUserToMember();

    $component->call('connect')->assertForbidden();

    expect(Node::query()->count())->toBe(0);
    Process::assertNothingRan();
});

it('refuses to install a reviewed server after the user lost the admin role', function () {
    $node = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id, 'metadata' => ['onboarding' => ['status' => 'review']]]);
    $component = Livewire::withQueryParams(['server' => $node->uuid])->test(Onboarding::class)
        ->assertSet('step', 2)
        ->set('clusterMode', 'existing')->set('clusterUuid', $this->cluster->uuid);
    demoteOnboardingUserToMember();

    $component->call('install')->assertForbidden();

    $node->refresh();
    expect($node->node_cluster_id)->toBeNull()
        ->and(data_get($node->metadata, 'onboarding.status'))->toBe('review');
    Queue::assertNotPushed(OnboardNodeJob::class);
});

it('refuses to retry a failed installation after the user lost the admin role', function () {
    $node = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id, 'node_cluster_id' => $this->cluster->id, 'metadata' => ['onboarding' => ['status' => 'failed']]]);
    $component = Livewire::withQueryParams(['server' => $node->uuid])->test(Onboarding::class);
    demoteOnboardingUserToMember();

    $component->call('retry')->assertForbidden();

    expect(data_get($node->refresh()->metadata, 'onboarding.status'))->toBe('failed');
    Queue::assertNotPushed(OnboardNodeJob::class);
});
