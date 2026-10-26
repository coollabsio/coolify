<?php

use App\Jobs\DeleteFileDownloadJob;
use App\Jobs\PrepareFileDownloadJob;
use App\Livewire\Settings\Advanced;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\ContainerFilesystemService;
use App\Services\FileDownloadStorageService;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->storage = S3Storage::create([
        'name' => 'Downloads',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'downloads',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => 0,
    ]);
});

function lifecycleStorage(MockHandler $handler): S3Storage
{
    $storage = new class extends S3Storage
    {
        public S3Client $client;

        public function filesystem(): FilesystemAdapter
        {
            $adapter = Mockery::mock(AwsS3V3Adapter::class);
            $adapter->shouldReceive('getClient')->andReturn($this->client);

            return $adapter;
        }
    };
    $storage->bucket = 'downloads';
    $storage->client = new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $handler,
    ]);

    return $storage;
}

it('adds the lifecycle rule and keeps the other bucket rules', function () {
    $put = null;
    $handler = new MockHandler([
        new Result(['Rules' => [
            ['ID' => 'keep-me', 'Filter' => ['Prefix' => 'logs/'], 'Status' => 'Enabled', 'Expiration' => ['Days' => 30]],
            ['ID' => FileDownloadStorageService::LIFECYCLE_RULE_ID, 'Filter' => ['Prefix' => 'old/'], 'Status' => 'Enabled', 'Expiration' => ['Days' => 9]],
        ]]),
        function (CommandInterface $command) use (&$put) {
            $put = $command->toArray();

            return new Result([]);
        },
    ]);

    (new FileDownloadStorageService)->ensureLifecycleRule(lifecycleStorage($handler));

    expect($put['LifecycleConfiguration']['Rules'])->toBe([
        ['ID' => 'keep-me', 'Filter' => ['Prefix' => 'logs/'], 'Status' => 'Enabled', 'Expiration' => ['Days' => 30]],
        ['ID' => FileDownloadStorageService::LIFECYCLE_RULE_ID, 'Filter' => ['Prefix' => FileDownloadStorageService::PREFIX], 'Status' => 'Enabled', 'Expiration' => ['Days' => 1]],
    ]);
});

it('adds the lifecycle rule to a bucket without a lifecycle configuration', function () {
    $put = null;
    $handler = new MockHandler([
        fn (CommandInterface $command) => new S3Exception('none', $command, ['code' => 'NoSuchLifecycleConfiguration']),
        function (CommandInterface $command) use (&$put) {
            $put = $command->toArray();

            return new Result([]);
        },
    ]);

    (new FileDownloadStorageService)->ensureLifecycleRule(lifecycleStorage($handler));

    expect($put['LifecycleConfiguration']['Rules'])->toHaveCount(1);
});

it('fails when the provider rejects the lifecycle rule', function () {
    $handler = new MockHandler([
        new Result(['Rules' => []]),
        fn (CommandInterface $command) => new S3Exception('not implemented', $command, ['code' => 'NotImplemented']),
    ]);

    (new FileDownloadStorageService)->ensureLifecycleRule(lifecycleStorage($handler));
})->throws(RuntimeException::class, 'Could not add the auto-delete lifecycle rule');

it('saves the download storage only after the lifecycle rule is added', function (bool $ruleAdded) {
    $user = User::factory()->create();
    $this->rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->rootTeam]);
    $downloads = Mockery::mock(FileDownloadStorageService::class);
    $downloads->shouldReceive('ensureBucketIsPrivate')->once();
    $expectation = $downloads->shouldReceive('ensureLifecycleRule')->once();
    if (! $ruleAdded) {
        $expectation->andThrow(new RuntimeException('Could not add the auto-delete lifecycle rule'));
    }
    app()->instance(FileDownloadStorageService::class, $downloads);

    $component = Livewire::test(Advanced::class)
        ->set('file_download_storage', 's3:'.$this->storage->id)
        ->call('instantSave');

    if ($ruleAdded) {
        $component->assertSet('file_download_storage', 's3:'.$this->storage->id);
        expect(InstanceSettings::find(0)->file_download_s3_storage_id)->toBe($this->storage->id);
    } else {
        $component->assertSet('file_download_storage', 'stream')->assertDispatched('error');
        expect(InstanceSettings::find(0)->file_download_s3_storage_id)->toBeNull();
    }
})->with([true, false]);

it('builds one S3 upload script that the non-root sudo parser keeps whole', function () {
    $command = (new ContainerFilesystemService(Server::factory()->make(), 'app-123'))
        ->buildS3UploadCommand("/app/it's.txt", false, 'https://s3.example.com/b/k?X-Amz-Signature=abc&x=1', ['Content-Disposition' => 'attachment; filename="a.txt"']);

    expect($command)->toStartWith('sh -c ')
        ->and($command)->toContain('mktemp', 'trap', 'curl -fsS', 'X-Amz-Signature=abc&x=1');
    expect(parseCommandsByLineForSudo(collect([$command]), Server::factory()->make(['user' => 'deploy'])))
        ->toBe(['sudo '.$command]);
});

it('uploads to S3, stores the link, and queues the delete', function () {
    $this->freezeTime();
    Storage::fake('ssh-keys');
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake([DeleteFileDownloadJob::class]);
    Process::fake(['*' => Process::result(output: '')]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->rootTeam->id]);
    $server = Server::factory()->create(['team_id' => $this->rootTeam->id, 'private_key_id' => $privateKey->id]);
    $downloads = Mockery::mock(FileDownloadStorageService::class);
    $downloads->shouldReceive('uploadUrl')->andReturn(['url' => 'https://s3.example.com/put', 'headers' => []]);
    $downloads->shouldReceive('downloadUrl')->andReturn('https://s3.example.com/get');

    (new PrepareFileDownloadJob('dl-1', 7, $server->id, 'app-123', '/data/a.txt', false, 'a.txt', $this->storage->id))->handle($downloads);

    expect(Cache::get(PrepareFileDownloadJob::cacheKey('dl-1')))->toBe(['user_id' => 7, 'status' => 'ready', 'url' => 'https://s3.example.com/get']);
    Process::assertRan(fn ($process) => str_contains($process->command, 'https://s3.example.com/put'));
    Bus::assertDispatched(DeleteFileDownloadJob::class, fn (DeleteFileDownloadJob $job) => $job->s3StorageId === $this->storage->id
        && str_starts_with($job->key, FileDownloadStorageService::PREFIX)
        && $job->delay->equalTo(now()->addHours(6)));
});

it('makes download links that expire after 15 minutes', function () {
    $this->storage->update(['endpoint' => 'https://1.1.1.1']);
    $url = (new FileDownloadStorageService)->downloadUrl($this->storage, FileDownloadStorageService::PREFIX.'abc');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect((int) $query['X-Amz-Expires'])->toBeBetween(890, 900);
});

it('stores a failure when the upload fails', function () {
    Bus::fake([DeleteFileDownloadJob::class]);
    $this->storage->update(['is_usable' => false]);

    (new PrepareFileDownloadJob('dl-2', 7, 1, 'app-123', '/data/a.txt', false, 'a.txt', $this->storage->id))->handle(new FileDownloadStorageService);

    $result = Cache::get(PrepareFileDownloadJob::cacheKey('dl-2'));
    expect($result)->toMatchArray(['user_id' => 7, 'status' => 'failed'])
        ->and($result['message'])->not->toContain('s3.example.com', 'X-Amz');
});

it('only deletes objects under the download prefix', function () {
    $downloads = Mockery::mock(FileDownloadStorageService::class);
    $downloads->shouldReceive('delete')->once()->with(Mockery::type(S3Storage::class), FileDownloadStorageService::PREFIX.'abc');

    (new DeleteFileDownloadJob($this->storage->id, FileDownloadStorageService::PREFIX.'abc'))->handle($downloads);
    (new DeleteFileDownloadJob($this->storage->id, 'backups/db.dump'))->handle($downloads);
});

function anonymousListingService(MockHandler $handler): FileDownloadStorageService
{
    return new class($handler) extends FileDownloadStorageService
    {
        public function __construct(private MockHandler $handler) {}

        protected function anonymousClient(S3Storage $storage): S3Client
        {
            return new S3Client(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => false, 'handler' => $this->handler]);
        }
    };
}

it('refuses a bucket that anonymous users can list', function () {
    $downloads = anonymousListingService(new MockHandler([new Result(['Contents' => []])]));

    $downloads->ensureBucketIsPrivate($this->storage);
})->throws(RuntimeException::class, 'allows anonymous listing');

it('accepts a bucket that denies anonymous listing', function () {
    $downloads = anonymousListingService(new MockHandler([
        fn (CommandInterface $command) => new S3Exception('denied', $command, ['code' => 'AccessDenied']),
    ]));

    $downloads->ensureBucketIsPrivate($this->storage);

    expect(true)->toBeTrue();
});

it('refuses the bundled MinIO because servers and browsers cannot reach it', function () {
    $this->storage->forceFill(['uuid' => 'minio', 'endpoint' => 'http://coolify-minio:9000'])->save();

    (new FileDownloadStorageService)->ensureBucketIsPrivate($this->storage);
})->throws(RuntimeException::class, 'uses an internal Docker host name');

it('explains an unreachable endpoint instead of accepting it', function () {
    $downloads = anonymousListingService(new MockHandler([
        fn (CommandInterface $command) => new S3Exception('cURL error 6: Could not resolve host', $command),
    ]));

    $downloads->ensureBucketIsPrivate($this->storage);
})->throws(RuntimeException::class, 'Could not connect to "Downloads"');
