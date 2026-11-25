<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $this->createBitbucketApplication = function (string $branch) use ($environment, $destination): Application {
        $application = Application::create([
            'name' => "app-{$branch}",
            'git_repository' => 'https://bitbucket.org/test-org/test-repo',
            'git_branch' => $branch,
            'build_pack' => 'dockerfile',
            'ports_exposes' => '3000',
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $application->forceFill(['manual_webhook_secret_bitbucket' => 'shared-bitbucket-secret'])->save();

        return $application;
    };
});

/**
 * @param  array<int, array<string, mixed>>  $changes
 */
function sendBitbucketMultiBranchPush(TestCase $test, array $changes): TestResponse
{
    $payload = json_encode([
        'push' => ['changes' => $changes],
        'repository' => ['full_name' => 'test-org/test-repo'],
    ]);

    return $test->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
        'HTTP_X-Event-Key' => 'repo:push',
        'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $payload, 'shared-bitbucket-secret'),
        'REMOTE_ADDR' => '203.0.113.10',
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

/**
 * @return array<string, mixed>
 */
function bitbucketBranchChange(string $branch, string $hash, string $message = 'Update'): array
{
    return [
        'new' => ['type' => 'branch', 'name' => $branch, 'target' => ['hash' => $hash]],
        'commits' => [['hash' => $hash, 'message' => $message]],
    ];
}

function bitbucketQueuedCommit(Application $application): ?string
{
    return ApplicationDeploymentQueue::query()->where('application_id', $application->id)->value('commit');
}

test('a push of several branches deploys each branch with its own commit', function () {
    $main = ($this->createBitbucketApplication)('main');
    $develop = ($this->createBitbucketApplication)('develop');

    sendBitbucketMultiBranchPush($this, [
        bitbucketBranchChange('main', 'aaaaaaa1'),
        bitbucketBranchChange('develop', 'bbbbbbb2'),
    ])->assertOk()->assertJsonCount(2);

    expect(bitbucketQueuedCommit($main))->toBe('aaaaaaa1')
        ->and(bitbucketQueuedCommit($develop))->toBe('bbbbbbb2');
});

test('a skip marker skips only the branch whose commits contain it', function () {
    $main = ($this->createBitbucketApplication)('main');
    $develop = ($this->createBitbucketApplication)('develop');

    sendBitbucketMultiBranchPush($this, [
        bitbucketBranchChange('main', 'aaaaaaa1', 'Docs [skip ci]'),
        bitbucketBranchChange('develop', 'bbbbbbb2'),
    ])->assertOk();

    expect(bitbucketQueuedCommit($main))->toBeNull()
        ->and(bitbucketQueuedCommit($develop))->toBe('bbbbbbb2');
});

test('a deleted branch first in the push does not stop the other branches', function () {
    $develop = ($this->createBitbucketApplication)('develop');

    sendBitbucketMultiBranchPush($this, [
        ['new' => null, 'old' => ['type' => 'branch', 'name' => 'feature', 'target' => ['hash' => 'ccccccc3']]],
        bitbucketBranchChange('develop', 'bbbbbbb2'),
    ])->assertOk()->assertJsonMissing(['message' => 'Nothing to do. No branch found in the request.']);

    expect(bitbucketQueuedCommit($develop))->toBe('bbbbbbb2');
});

test('a push that only deletes branches does nothing', function () {
    ($this->createBitbucketApplication)('main');

    sendBitbucketMultiBranchPush($this, [
        ['new' => null, 'old' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'ccccccc3']]],
    ])->assertOk()->assertJson(['message' => 'Nothing to do. No branch found in the request.']);

    expect(ApplicationDeploymentQueue::query()->exists())->toBeFalse();
});

test('a numeric branch name is matched as a string', function () {
    $release = ($this->createBitbucketApplication)('2026');

    sendBitbucketMultiBranchPush($this, [bitbucketBranchChange('2026', 'ddddddd4')])->assertOk();

    expect(bitbucketQueuedCommit($release))->toBe('ddddddd4');
});

test('a Bitbucket Data Center push of several branches deploys each branch with its own commit', function () {
    $main = ($this->createBitbucketApplication)('main');
    $develop = ($this->createBitbucketApplication)('develop');
    $main->update(['git_repository' => 'https://bitbucket.example.com/scm/test-org/test-repo.git']);
    $develop->update(['git_repository' => 'https://bitbucket.example.com/scm/test-org/test-repo.git']);
    $payload = json_encode([
        'eventKey' => 'repo:refs_changed',
        'repository' => ['slug' => 'test-repo', 'project' => ['key' => 'test-org']],
        'changes' => [
            ['ref' => ['displayId' => 'main', 'type' => 'BRANCH'], 'toHash' => str_repeat('a', 40), 'type' => 'UPDATE'],
            ['ref' => ['displayId' => 'v1.0', 'type' => 'TAG'], 'toHash' => str_repeat('e', 40), 'type' => 'ADD'],
            ['ref' => ['displayId' => 'develop', 'type' => 'BRANCH'], 'toHash' => str_repeat('b', 40), 'type' => 'ADD'],
        ],
        'commits' => [['message' => 'Update']],
    ]);

    $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
        'HTTP_X-Event-Key' => 'repo:refs_changed',
        'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $payload, 'shared-bitbucket-secret'),
        'REMOTE_ADDR' => '203.0.113.10',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk()->assertJsonCount(2);

    expect(bitbucketQueuedCommit($main))->toBe(str_repeat('a', 40))
        ->and(bitbucketQueuedCommit($develop))->toBe(str_repeat('b', 40));
});
