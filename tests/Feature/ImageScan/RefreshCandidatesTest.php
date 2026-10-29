<?php

use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Jobs\RefreshCandidatesJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\ImageScanPolicy;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\ImageScan\CandidateEvaluator;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GrypeReportParser;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\ScannerCommands;
use App\Services\ImageScan\SecurityActions;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const CANDIDATES_CURRENT_HEX = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

const CANDIDATES_REPO_DIGEST = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

const CANDIDATES_NEW_BUILD = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

const CANDIDATES_LINE = 'sha256:3333333333333333333333333333333333333333333333333333333333333333';

const CANDIDATES_SECRET = 'S3cr3t-registry-password';

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);

    $this->team = Team::factory()->create();
    $this->server = candidatesServer($this->team);
    ImageScanPolicy::factory()->create([
        'scope_type' => $this->server->getMorphClass(),
        'scope_id' => $this->server->id,
        'gate_mode' => 'strict',
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->tags = ['15.0-alpine', '15.11-alpine', '15.15-alpine', '15-alpine', '16.4-alpine', '15.16-rc1-alpine'];
    $this->digests = ['15.0-alpine' => CANDIDATES_REPO_DIGEST, '15.15-alpine' => CANDIDATES_LINE];
    $this->grype = [substr(CANDIDATES_LINE, 7) => 'grype-candidate-15-alpine', substr(CANDIDATES_NEW_BUILD, 7) => 'grype-candidate-15.11'];
    $this->dockerConfig = '';
    $this->anonymousFails = false;
    $this->diskFree = '50000000000 50000000000';
    $this->grypeCut = false;
    $this->grypeEdit = null;
    $this->beforeDigest = null;
});

function candidatesServer(Team $team): Server
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => (PrivateKey::query()->where('team_id', $team->id)->first() ?? PrivateKey::factory()->create(['team_id' => $team->id]))->id,
    ]);
    $server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'is_image_scan_enabled' => true])->saveQuietly();

    return $server->fresh();
}

function candidatesDatabase(string $image): StandalonePostgresql
{
    return StandalonePostgresql::forceCreate([
        'name' => 'pg',
        'postgres_password' => 'secret',
        'image' => $image,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

/**
 * @return list<Finding>
 */
function candidatesFindings(string $fixture = 'grype'): array
{
    return (new GrypeReportParser)->parse(file_get_contents(base_path("tests/Fixtures/image-scan/{$fixture}.ndjson")))['findings'];
}

function candidatesScan(string $imageRef = 'postgres:15.0-alpine', ?string $repoDigest = CANDIDATES_REPO_DIGEST, ?StandalonePostgresql $database = null): ImageScan
{
    $database ??= candidatesDatabase('postgres:15.0-alpine');
    $sbom = ImageSbom::factory()->create(['server_id' => test()->server->id, 'image_id' => 'sha256:'.CANDIDATES_CURRENT_HEX]);

    $scan = ImageScan::create([
        'server_id' => test()->server->id,
        'image_sbom_id' => $sbom->id,
        'image_id' => 'sha256:'.CANDIDATES_CURRENT_HEX,
        'repo_digest' => $repoDigest,
        'resource_type' => $database->getMorphClass(),
        'resource_id' => $database->id,
        'image_ref' => $imageRef,
        'status' => ImageScanStatus::Completed,
        'findings' => array_map(fn (Finding $finding) => $finding->toArray(), candidatesFindings()),
        'summary' => ['eol_distro' => ['name' => 'alpine', 'version' => '3.16.2'], 'truncated' => false],
        'builds_image' => false,
        'scanned_at' => now(),
    ]);

    return $scan;
}

/**
 * The command with the quotes of `sh -c '…'` scripts undone, so arguments read `'tag' 'ls' 'repo'`.
 */
function candidatesCommand(PendingProcess $process): string
{
    return str_replace("'\\''", "'", remoteProcessScript($process));
}

function candidatesPayload(string $step, string $payload, int $code = 0, ?int $size = null): string
{
    $size ??= strlen($payload);

    return "coolify-scan-exit {$step} {$code} {$size}\ncoolify-scan-payload\n{$payload}";
}

/**
 * Fakes the server: registry calls answer from test()->tags and test()->digests (by tag), candidate scans
 * from test()->grype (by SBOM name). With test()->anonymousFails, registry calls without credentials fail.
 */
function candidatesFake(): void
{
    Process::fake(function (PendingProcess $process) {
        $command = candidatesCommand($process);
        $withAuth = str_contains($command, 'DOCKER_CONFIG=/auth');
        $registry = str_contains($command, 'coolify-scan-exit tags') || str_contains($command, 'coolify-scan-exit digest');
        if ($registry && test()->anonymousFails && ! $withAuth) {
            return Process::result(output: str_contains($command, 'coolify-scan-exit tags') ? candidatesPayload('tags', '', 1) : candidatesPayload('digest', '', 1));
        }

        return Process::result(output: match (true) {
            str_contains($command, '.docker/config.json') => test()->dockerConfig,
            str_contains($command, 'df -P') => test()->diskFree,
            str_contains($command, 'docker image inspect') => "coolify-scan-exit inspect 0\ncoolify-scan-payload\nsha256:".CANDIDATES_CURRENT_HEX.'|postgres@'.CANDIDATES_REPO_DIGEST.'|linux/amd64|2000000000',
            str_contains($command, 'coolify-scan-exit tags') => candidatesPayload('tags', implode("\n", test()->tags)),
            str_contains($command, 'coolify-scan-exit digest') && test()->beforeDigest !== null && (test()->beforeDigest)($command) => candidatesPayload('digest', '', 1),
            str_contains($command, 'coolify-scan-exit digest') => preg_match("/'digest' '[^']*:([A-Za-z0-9_.-]+)'/", $command, $tag) === 1 && isset(test()->digests[$tag[1]])
                ? candidatesPayload('digest', test()->digests[$tag[1]])
                : candidatesPayload('digest', '', 1),
            str_contains($command, "'registry:") => 'coolify-scan-exit sbom 0',
            str_contains($command, 'coolify-scan-exit packages') => candidatesPayload('packages', file_get_contents(base_path('tests/Fixtures/image-scan/syft-packages.ndjson'))),
            str_contains($command, 'coolify-scan-exit grype') => preg_match('#sboms/([a-f0-9]{64})\.json#', $command, $hex) === 1 && isset(test()->grype[$hex[1]])
                ? candidatesPayload('grype', (test()->grypeEdit ?? fn (string $payload) => $payload)(file_get_contents(base_path('tests/Fixtures/image-scan/'.test()->grype[$hex[1]].'.ndjson'))), size: test()->grypeCut ? ScannerCommands::OUTPUT_LIMIT + 1 : null)
                : candidatesPayload('grype', '', 125),
            default => '',
        });
    });
}

function candidatesRan(string $needle): int
{
    $count = 0;
    Process::assertRan(function (PendingProcess $process) use ($needle, &$count) {
        if (str_contains(candidatesCommand($process), $needle)) {
            $count++;
        }

        return true;
    });

    return $count;
}

function candidatesScansToday(): int
{
    return (int) test()->server->settings()->value('image_candidate_scans_today');
}

it('recommends the newest tag of the line when it has fewer findings and lists what it fixes', function () {
    candidatesFake();
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    $scan->refresh();
    $line = collect($scan->candidateEntries())->firstWhere('kind', 'line');
    $major = collect($scan->candidateEntries())->firstWhere('kind', 'major');

    expect($scan->candidateStatus())->toBe('ok')
        ->and($scan->candidates['checked_at'])->not->toBeNull()
        ->and($line)->toMatchArray(['ref' => 'postgres:15.15-alpine', 'digest' => CANDIDATES_LINE, 'better' => true, 'eol' => null])
        ->and($line['fixed_ids'])->toContain('CVE-2025-1094')
        ->and($line['fixed_ids'])->not->toContain('CVE-2023-2650')
        ->and($line['left'])->toBe(['critical' => 3, 'high' => 32, 'medium' => 0])
        ->and($line['fixes']['critical'] + $line['fixes']['high'])->toBeGreaterThan(0)
        ->and($line['scanned_at'])->not->toBeNull()
        ->and($major)->toMatchArray(['ref' => 'postgres:16.4-alpine', 'better' => false])
        ->and(collect($scan->candidateEntries())->where('kind', 'same_tag'))->toBeEmpty()
        ->and(ResourceScans::storedFixedCount($scan))->toBeGreaterThan(0)
        ->and($scan->summary['eol_distro'])->toBe(['name' => 'alpine', 'version' => '3.16.2'])
        ->and(candidatesScansToday())->toBe(1)
        ->and(ImageSbom::query()->where('server_id', $this->server->id)->where('image_id', CANDIDATES_LINE)->first()?->packages)->not->toBeEmpty();

    Process::assertRan(fn (PendingProcess $process) => str_contains(candidatesCommand($process), "'tag' 'ls' 'docker.io/library/postgres'"));
    Process::assertRan(fn (PendingProcess $process) => str_contains(candidatesCommand($process), "'registry:postgres@".CANDIDATES_LINE."'")
        && str_contains(candidatesCommand($process), "'--platform' 'linux/amd64'"));
    expect(candidatesRan('DOCKER_CONFIG'))->toBe(0)
        ->and(candidatesRan('.docker/config.json'))->toBe(0);
});

it('says that no newer version is better when the line candidate adds findings with an exploit signal', function () {
    candidatesFake();
    $this->grype[substr(CANDIDATES_LINE, 7)] = 'grype-candidate-15.11';
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    $scan->refresh();
    expect($scan->candidateStatus())->toBe('no_better')
        ->and(collect($scan->candidateEntries())->firstWhere('kind', 'line')['better'])->toBeFalse()
        ->and(ResourceScans::storedFixedCount($scan))->toBe(0)
        ->and(RefreshCandidates::message($scan))->toBe('No newer version of postgres is better yet. Coolify checks again every day.');
});

it('offers the newest patch of the same minor and does not scan the line when the patch is safer', function () {
    candidatesFake();
    $this->tags = ['15.0.1-alpine', '15.0.4-alpine', '15.2.0-alpine'];
    $this->digests = ['15.0.1-alpine' => CANDIDATES_REPO_DIGEST, '15.0.4-alpine' => CANDIDATES_LINE, '15.2.0-alpine' => CANDIDATES_NEW_BUILD];
    $scan = candidatesScan('postgres:15.0.1-alpine');

    RefreshCandidates::run($scan);

    $scan->refresh();
    expect($scan->candidateStatus())->toBe('ok')
        ->and(collect($scan->candidateEntries())->where('kind', 'line')->values()->all())->toHaveCount(1)
        ->and(collect($scan->candidateEntries())->firstWhere('kind', 'line'))->toMatchArray(['ref' => 'postgres:15.0.4-alpine', 'better' => true])
        ->and(candidatesRan("'registry:"))->toBe(1);
});

it('scans the newest tag of the line when the newest patch is not safer', function () {
    candidatesFake();
    $this->tags = ['15.0.1-alpine', '15.0.4-alpine', '15.2.0-alpine'];
    $this->digests = ['15.0.1-alpine' => CANDIDATES_REPO_DIGEST, '15.0.4-alpine' => CANDIDATES_NEW_BUILD, '15.2.0-alpine' => CANDIDATES_LINE];
    $scan = candidatesScan('postgres:15.0.1-alpine');

    RefreshCandidates::run($scan);

    $scan->refresh();
    $lines = collect($scan->candidateEntries())->where('kind', 'line')->values();
    expect($scan->candidateStatus())->toBe('ok')
        ->and($lines->pluck('better', 'ref')->all())->toBe(['postgres:15.0.4-alpine' => false, 'postgres:15.2.0-alpine' => true])
        ->and(SecurityActions::forScan($scan, IgnoreMatcher::fromIgnores(collect()))['actions'][0]->target)->toBe('15.2.0-alpine');
});

it('scans the newest major for information when nothing of the same major is safer', function () {
    candidatesFake();
    $this->grype[substr(CANDIDATES_LINE, 7)] = 'grype-candidate-15.11';
    $this->digests['16.4-alpine'] = CANDIDATES_NEW_BUILD;
    $this->grype[substr(CANDIDATES_NEW_BUILD, 7)] = 'grype-candidate-15-alpine';
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    $scan->refresh();
    $major = collect($scan->candidateEntries())->firstWhere('kind', 'major');
    expect($scan->candidateStatus())->toBe('no_better')
        ->and($major)->toMatchArray(['ref' => 'postgres:16.4-alpine', 'better' => false, 'fixes_problems' => true])
        ->and($major['fixed_ids'])->toContain('CVE-2025-1094')
        ->and($scan->updateCandidates())->toBe([])
        ->and(ResourceScans::storedFixedCount($scan))->toBe(0)
        ->and(candidatesRan("'registry:"))->toBe(2);
});

it('keeps the status of the check when the scan of the newest major fails', function () {
    candidatesFake();
    $this->grype[substr(CANDIDATES_LINE, 7)] = 'grype-candidate-15.11';
    $this->digests['16.4-alpine'] = 'sha256:'.str_repeat('4', 64);
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    $scan->refresh();
    expect($scan->candidateStatus())->toBe('no_better')
        ->and($scan->candidates['error'])->toBeNull()
        ->and(collect($scan->candidateEntries())->firstWhere('kind', 'major'))->toMatchArray(['ref' => 'postgres:16.4-alpine', 'scanned_at' => null])
        ->and(collect($scan->candidateEntries())->firstWhere('kind', 'major'))->not->toHaveKey('fixes_problems');
});

it('checks the newest tag of the line also after a newer build of the same tag and a newer patch', function () {
    candidatesFake();
    $patch = 'sha256:'.str_repeat('5', 64);
    $this->tags = ['15.0.1-alpine', '15.0.4-alpine', '15.2.0-alpine'];
    $this->digests = ['15.0.1-alpine' => CANDIDATES_NEW_BUILD, '15.0.4-alpine' => $patch, '15.2.0-alpine' => CANDIDATES_LINE];
    $this->grype = [
        substr(CANDIDATES_NEW_BUILD, 7) => 'grype-candidate-15.11',
        substr($patch, 7) => 'grype-candidate-15.11',
        substr(CANDIDATES_LINE, 7) => 'grype-candidate-15-alpine',
    ];
    $scan = candidatesScan('postgres:15.0.1-alpine');

    RefreshCandidates::run($scan);

    $scan->refresh();
    expect(collect($scan->candidateEntries())->map(fn (array $entry) => [$entry['kind'], $entry['ref'], $entry['better']])->all())->toBe([
        ['same_tag', 'postgres:15.0.1-alpine', false],
        ['line', 'postgres:15.0.4-alpine', false],
        ['line', 'postgres:15.2.0-alpine', true],
    ])
        ->and($scan->candidateStatus())->toBe('ok')
        ->and(candidatesRan("'registry:"))->toBe(3)
        ->and(candidatesScansToday())->toBe(3);
});

it('does not scan the newest major for information when fewer than 5 scans are left on the server today', function () {
    candidatesFake();
    $this->server->settings->forceFill(['image_candidate_scans_today' => 15, 'image_candidate_scans_date' => today()])->saveQuietly();
    $this->grype[substr(CANDIDATES_LINE, 7)] = 'grype-candidate-15.11';
    $this->digests['16.4-alpine'] = CANDIDATES_NEW_BUILD;
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    $scan->refresh();
    expect($scan->candidateStatus())->toBe('no_better')
        ->and(collect($scan->candidateEntries())->firstWhere('kind', 'major'))->toMatchArray(['ref' => 'postgres:16.4-alpine', 'scanned_at' => null])
        ->and(collect($scan->candidateEntries())->firstWhere('kind', 'major'))->not->toHaveKey('fixes_problems')
        ->and(candidatesRan("'registry:"))->toBe(1)
        ->and(candidatesScansToday())->toBe(16);
});

it('keeps the result of the check when the server lock is busy for the scan of the newest major', function () {
    candidatesFake();
    $this->grype[substr(CANDIDATES_LINE, 7)] = 'grype-candidate-15.11';
    $this->digests['16.4-alpine'] = CANDIDATES_NEW_BUILD;
    $this->grype[substr(CANDIDATES_NEW_BUILD, 7)] = 'grype-candidate-15-alpine';
    // A deploy gate starts to wait for the lock while Coolify asks for the digest of the major.
    $this->beforeDigest = function (string $command): bool {
        if (str_contains($command, ":16.4-alpine'")) {
            Cache::put(ScanImage::gateWaitingCacheKey($this->server), true, 120);
        }

        return false;
    };
    $scan = candidatesScan();

    RefreshCandidates::run($scan, 0, retryWhenBusy: true);

    $scan->refresh();
    expect($scan->candidateStatus())->toBe('no_better')
        ->and($scan->candidates['error'])->toBeNull()
        ->and(collect($scan->candidateEntries())->firstWhere('kind', 'major'))->not->toHaveKey('fixes_problems')
        ->and(candidatesRan("'registry:"))->toBe(1);
});

it('checks the same tag for a newer build and scans at most 2 candidates', function () {
    candidatesFake();
    $this->digests['15.0-alpine'] = CANDIDATES_NEW_BUILD;
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    $scan->refresh();
    $sameTag = collect($scan->candidateEntries())->firstWhere('kind', 'same_tag');
    expect($sameTag)->toMatchArray(['ref' => 'postgres:15.0-alpine', 'digest' => CANDIDATES_NEW_BUILD, 'better' => false])
        ->and($scan->candidateStatus())->toBe('ok')
        ->and(candidatesRan("'registry:"))->toBe(2)
        ->and(candidatesScansToday())->toBe(2);
    Process::assertRan(fn (PendingProcess $process) => str_contains(candidatesCommand($process), "'image' 'digest' 'postgres:15.0-alpine'")
        && ! str_contains(candidatesCommand($process), "'digest' '--platform'"));
});

it('reuses the SBOM of a candidate digest on the same server and does not count it', function () {
    candidatesFake();
    ImageSbom::factory()->create(['server_id' => $this->server->id, 'image_id' => CANDIDATES_LINE, 'last_used_at' => now()->subDays(3)]);
    ImageSbom::factory()->create(['server_id' => candidatesServer($this->team)->id, 'image_id' => CANDIDATES_NEW_BUILD]);
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidateStatus())->toBe('ok')
        ->and(candidatesRan("'registry:"))->toBe(0)
        ->and(candidatesRan('coolify-scan-exit grype'))->toBe(1)
        ->and(candidatesScansToday())->toBe(0)
        ->and(ImageSbom::query()->where('image_id', CANDIDATES_LINE)->sole()->last_used_at->isToday())->toBeTrue();
});

it('scans no new candidate after 20 scans on the server today', function () {
    candidatesFake();
    $this->server->settings->forceFill(['image_candidate_scans_today' => 20, 'image_candidate_scans_date' => today()])->saveQuietly();
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    expect(candidatesRan("'registry:"))->toBe(0)
        ->and(candidatesScansToday())->toBe(20)
        ->and($scan->fresh()->candidateStatus())->toBe('failed')
        ->and($scan->fresh()->candidates['error'])->toBe('daily_limit');
});

it('starts a new daily count on a new day', function () {
    candidatesFake();
    $this->server->settings->forceFill(['image_candidate_scans_today' => 20, 'image_candidate_scans_date' => today()->subDay()])->saveQuietly();

    RefreshCandidates::run(candidatesScan());

    expect(candidatesRan("'registry:"))->toBe(1)
        ->and(candidatesScansToday())->toBe(1)
        ->and($this->server->settings()->first()->image_candidate_scans_date->toDateString())->toBe(today()->toDateString());
});

it('uses the login of the image registry only after an anonymous request fails', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });
    candidatesFake();
    $this->anonymousFails = true;
    $this->tags = ['1.0', '1.1'];
    $this->digests = ['1.0' => CANDIDATES_REPO_DIGEST, '1.1' => CANDIDATES_LINE];
    $this->dockerConfig = json_encode(['auths' => [
        'https://index.docker.io/v1/' => ['auth' => base64_encode('hub:'.CANDIDATES_SECRET.'-hub')],
        'ghcr.io' => ['auth' => base64_encode('acme:'.CANDIDATES_SECRET)],
        'registry.example.com' => ['auth' => base64_encode('other:'.CANDIDATES_SECRET.'-other')],
    ]]);
    $scan = candidatesScan('ghcr.io/acme/api:1.0');

    RefreshCandidates::run($scan);

    $configs = [];
    Process::assertRan(function (PendingProcess $process) use (&$configs) {
        if (preg_match_all("#echo '([A-Za-z0-9+/=]+)' \\| base64 -d \\| tee '[^']*/auth/config\\.json'#", candidatesCommand($process), $matches) > 0) {
            array_push($configs, ...array_map(fn (string $encoded) => json_decode(base64_decode($encoded), true), $matches[1]));
        }

        return true;
    });

    expect($configs)->not->toBeEmpty()
        ->and(array_unique(array_map(fn (array $config) => json_encode($config), $configs)))->toBe([json_encode(['auths' => ['ghcr.io' => ['auth' => base64_encode('acme:'.CANDIDATES_SECRET)]]])])
        ->and(candidatesRan('.docker/config.json'))->toBe(1)
        ->and($scan->fresh()->candidateStatus())->toBe('ok');

    $stored = json_encode([ImageScan::query()->get()->toArray(), Activity::query()->get()->toArray(), Cache::get('image-scan:last-error:'.$scan->resource_type.':'.$scan->resource_id), $logged]);
    expect($stored)->not->toContain(CANDIDATES_SECRET)
        ->and($stored)->not->toContain(base64_encode('acme:'.CANDIDATES_SECRET));
});

it('asks for a login when the registry login uses a credential helper', function () {
    candidatesFake();
    $this->anonymousFails = true;
    $this->dockerConfig = json_encode(['credHelpers' => ['ghcr.io' => 'gh']]);
    $scan = candidatesScan('ghcr.io/acme/api:1.0');

    RefreshCandidates::run($scan);

    $scan->refresh();
    expect($scan->candidateStatus())->toBe('auth_required')
        ->and($scan->candidateEntries())->toBe([])
        ->and(RefreshCandidates::message($scan))->toBe("Coolify cannot read the tags of ghcr.io/acme/api. Log in to ghcr.io on {$this->server->name}.")
        ->and(candidatesRan('DOCKER_CONFIG=/auth'))->toBe(0);
});

it('reads the tags of an image from a registry with a port', function () {
    candidatesFake();
    $this->tags = ['1.2', '1.3', '2.0'];
    $this->digests = ['1.2' => CANDIDATES_REPO_DIGEST, '1.3' => CANDIDATES_LINE];
    $scan = candidatesScan('localhost:5000/team/app:1.2');

    RefreshCandidates::run($scan);

    Process::assertRan(fn (PendingProcess $process) => str_contains(candidatesCommand($process), "'tag' 'ls' 'localhost:5000/team/app'"));
    Process::assertRan(fn (PendingProcess $process) => str_contains(candidatesCommand($process), "'registry:localhost:5000/team/app@".CANDIDATES_LINE."'"));
    expect(collect($scan->fresh()->candidateEntries())->pluck('ref')->all())->toBe(['localhost:5000/team/app:1.3', 'localhost:5000/team/app:2.0']);
});

it('cannot compare versions of a tag that is not a version', function () {
    candidatesFake();
    $this->digests = ['main' => CANDIDATES_REPO_DIGEST];
    $scan = candidatesScan('ghcr.io/acme/api:main');

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidateStatus())->toBe('cannot_compare')
        ->and(candidatesRan('coolify-scan-exit tags'))->toBe(0)
        ->and(RefreshCandidates::message($scan->fresh()))->toBe('Coolify cannot compare versions of this image. Check the image page for a newer version.');
});

it('cannot compare versions of an image pinned by digest and runs nothing on the server', function () {
    candidatesFake();
    $scan = candidatesScan('ghcr.io/acme/api@'.CANDIDATES_REPO_DIGEST);

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidateStatus())->toBe('cannot_compare');
    Process::assertNothingRan();
});

it('records a failure and does not throw when the registry cannot be read', function () {
    Process::fake(fn () => Process::result(output: '', errorOutput: 'ssh: connect to host', exitCode: 255));
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidateStatus())->toBe('failed');
});

it('does not recommend a line candidate that adds a finding with an exploit signal, even when it fixes others', function () {
    candidatesFake();
    $scan = candidatesScan();
    // GO-2026-4337 (CVSS 10) of gosu, which 15.0-alpine does not have, gets an EPSS of 0.5: a likely exploit.
    $this->grypeEdit = fn (string $payload) => str_replace('"epss":0.00915', '"epss":0.5', $payload);

    RefreshCandidates::run($scan);

    $line = collect($scan->fresh()->candidateEntries())->firstWhere('kind', 'line');
    expect($scan->fresh()->candidateStatus())->toBe('no_better')
        ->and($line['better'])->toBeFalse()
        ->and($line['fixed_ids'])->toContain('CVE-2025-1094');
});

it('does nothing for an image without relevant findings on a supported distro', function () {
    candidatesFake();
    $scan = candidatesScan();
    $scan->update([
        'findings' => array_map(fn (Finding $finding) => [...$finding->toArray(), 'severity' => 'medium', 'kev' => false, 'epss' => null], candidatesFindings()),
        'summary' => [...$scan->summary, 'eol_distro' => null],
    ]);

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidates)->toBeNull();
    Process::assertNothingRan();
});

it('looks for candidates for an end-of-life distro or a relevant finding without a fix', function (string $case) {
    candidatesFake();
    $scan = candidatesScan();
    $finding = fn (array $data) => [...candidatesFindings()[0]->toArray(), 'aliases' => [], 'fixState' => 'not-fixed', 'fixedIn' => null, ...$data];
    $scan->update(match ($case) {
        'eol' => ['findings' => []],
        'not fixed' => ['findings' => [$finding(['id' => 'CVE-2025-1094', 'severity' => 'high'])], 'summary' => [...$scan->summary, 'eol_distro' => null]],
    });

    expect(RefreshCandidates::needsCandidates($scan->fresh()))->toBeTrue();
})->with(['eol', 'not fixed']);

it('looks for candidates for a fixable high known exploited finding', function () {
    candidatesFake();
    $scan = candidatesScan();
    $kev = Finding::fromArray([
        'id' => 'CVE-2025-1094', 'aliases' => [], 'severity' => 'high', 'package' => 'postgresql', 'version' => '15.0', 'type' => 'binary',
        'artifactId' => 'pg', 'fixedIn' => '15.11', 'fixState' => 'fixed', 'kev' => true,
    ]);
    $scan->update(['findings' => [$kev->toArray()], 'summary' => [...$scan->summary, 'eol_distro' => null]]);

    RefreshCandidates::run($scan);

    $line = collect($scan->fresh()->candidateEntries())->firstWhere('kind', 'line');
    expect($scan->fresh()->candidateStatus())->toBe('ok')
        ->and($line['better'])->toBeTrue()
        ->and($line['fixed_ids'])->toBe(['CVE-2025-1094'])
        ->and($line['fixes'])->toBe(['critical' => 0, 'high' => 1, 'medium' => 0])
        // Every relevant critical, high, or medium finding of 15.15 is new: the current image had only the exploited one.
        ->and($line['added'])->toBe(array_sum($line['left']));
});

it('does nothing for an application that Coolify builds', function () {
    candidatesFake();
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
    $scan = candidatesScan();
    $scan->update(['resource_type' => $application->getMorphClass(), 'resource_id' => $application->id, 'image_ref' => $application->uuid.':abc123']);

    RefreshCandidates::run($scan->fresh());

    expect($scan->fresh()->candidates)->toBeNull();
    Process::assertNothingRan();
});

it('marks the findings that a better candidate fixes as fixed by an update', function () {
    candidatesFake();
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    $scan = $scan->fresh();
    $byFinding = SecurityActions::forScan($scan, IgnoreMatcher::forResource($scan->resource))['byFinding'];

    expect($byFinding)->toHaveKey('CVE-2025-1094');
});

it('is a unique queued job per scan', function () {
    $scan = candidatesScan();
    $job = new RefreshCandidatesJob($scan);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $scan->id)
        ->and($job->queue)->toBe('default');
});

function candidatesQueuedJob(RefreshCandidatesJob $refreshJob, int $attempt): Job
{
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $refreshJob->setJob($queueJob);

    return $queueJob;
}

it('does not wait for a busy server lock in the queued job and runs again later', function () {
    candidatesFake();
    $lock = ScanImage::serverLock($this->server);
    $lock->get();
    $scan = candidatesScan();

    $job = new RefreshCandidatesJob($scan);
    candidatesQueuedJob($job, 2)->shouldReceive('release')->once()->with(120);
    $job->handle();
    $lock->release();

    expect(candidatesRan("'registry:"))->toBe(0)
        ->and($scan->fresh()->candidates)->toBeNull()
        ->and(candidatesScansToday())->toBe(0);
});

it('leaves the server lock to a deploy gate that waits for it', function () {
    candidatesFake();
    Cache::put(ScanImage::gateWaitingCacheKey($this->server), true, 120);
    $scan = candidatesScan();

    $job = new RefreshCandidatesJob($scan);
    candidatesQueuedJob($job, 1)->shouldReceive('release')->once()->with(60);
    $job->handle();

    expect(candidatesRan("'registry:"))->toBe(0)
        ->and($scan->fresh()->candidates)->toBeNull();
});

it('records the busy lock on the last attempt of the queued job', function () {
    candidatesFake();
    $lock = ScanImage::serverLock($this->server);
    $lock->get();
    $scan = candidatesScan();

    $job = new RefreshCandidatesJob($scan);
    candidatesQueuedJob($job, $job->tries)->shouldReceive('release')->never();
    $job->handle();
    $lock->release();

    expect($scan->fresh()->candidates['error'])->toBe('lock_busy');
});

it('skips the queued job when the candidates were checked meanwhile', function () {
    Process::fake();
    $scan = candidatesScan();
    $scan->forceFill(['candidates' => ['status' => 'no_better', 'checked_at' => now()->toIso8601String(), 'error' => null, 'entries' => []]])->save();

    (new RefreshCandidatesJob($scan))->handle();

    Process::assertNothingRan();
});

it('checks the disk space for the current image size before a registry scan', function () {
    candidatesFake();
    $this->diskFree = '4000000000 50000000000';
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    expect(candidatesRan("'registry:"))->toBe(0)
        ->and(candidatesRan('df -P'))->toBe(1)
        ->and($scan->fresh()->candidateStatus())->toBe('failed')
        ->and($scan->fresh()->candidates['error'])->toBe('disk')
        ->and(candidatesScansToday())->toBe(0);
});

it('runs a registry scan when the disk has image size × 2 + 1 GB free', function () {
    candidatesFake();
    $this->diskFree = '5100000000 5100000000';

    RefreshCandidates::run(candidatesScan());

    expect(candidatesRan("'registry:"))->toBe(1);
});

it('does not ask for a login when anonymous registry access worked', function () {
    candidatesFake();
    $this->tags = ['1.0', '1.1'];
    $this->digests = ['1.0' => CANDIDATES_REPO_DIGEST];
    $scan = candidatesScan('ghcr.io/acme/api:1.0');

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidateStatus())->toBe('failed')
        ->and($scan->fresh()->candidates['error'])->toBe('registry')
        ->and(candidatesRan('.docker/config.json'))->toBe(0);
});

it('does not ask for a login for a Docker Hub official image', function () {
    candidatesFake();
    $this->anonymousFails = true;
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidateStatus())->toBe('failed')
        ->and(candidatesRan('.docker/config.json'))->toBe(1);
});

it('never recommends a candidate whose scan output was cut', function () {
    candidatesFake();
    $this->grypeCut = true;
    $scan = candidatesScan();

    RefreshCandidates::run($scan);

    expect(collect($scan->fresh()->candidateEntries())->firstWhere('kind', 'line')['better'])->toBeFalse()
        ->and($scan->fresh()->candidateStatus())->toBe('no_better');
});

it('records a busy server lock and scans nothing', function () {
    candidatesFake();
    $lock = Cache::lock("image-scan:{$this->server->id}", 60);
    $lock->get();
    $scan = candidatesScan();

    RefreshCandidates::run($scan, 1);

    expect(candidatesRan("'registry:"))->toBe(0)
        ->and($scan->fresh()->candidateStatus())->toBe('failed')
        ->and($scan->fresh()->candidates['error'])->toBe('lock_busy');
    $lock->release();
});

it('records that the tag list was cut at 10,000 tags', function () {
    candidatesFake();
    $scan = candidatesScan();

    RefreshCandidates::run($scan);
    expect($scan->fresh()->candidates['tags_truncated'])->toBeFalse();

    $this->tags = [...array_map(fn (int $patch) => "15.{$patch}-alpine", range(100, 10_100)), '15.0-alpine'];
    RefreshCandidates::run($scan->fresh());
    expect($scan->fresh()->candidates['tags_truncated'])->toBeTrue();
});

it('allows a candidate job to run up to the worst case of two candidate scans', function () {
    expect((new RefreshCandidatesJob(candidatesScan()))->timeout)->toBeGreaterThanOrEqual(6000);
});

/**
 * A Dockerfile build from postgres:15.0-alpine: the app has the findings of the base image (grype fixture) and one
 * more of a package that the Dockerfile added (CVE-2099-0001). The registry scan of the current base image answers
 * with the grype fixture, the line candidate 15.15-alpine with the 15-alpine fixture.
 */
function candidatesDockerfileScan(string $baseImageRef = 'postgres:15.0-alpine', bool $withFindings = true): ImageScan
{
    test()->grype = [...test()->grype, substr(CANDIDATES_REPO_DIGEST, 7) => 'grype'];
    $application = Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
    $added = Finding::fromArray([
        'id' => 'CVE-2099-0001', 'aliases' => [], 'severity' => 'High', 'package' => 'curl', 'version' => '8.0.0-r0',
        'type' => 'apk', 'artifactId' => 'added-curl', 'fixedIn' => '8.0.1-r0', 'fixState' => 'fixed', 'kev' => false,
        'epss' => null, 'risk' => 9.0, 'summary' => '', 'layer' => 'app',
    ]);
    $scan = candidatesScan();
    $scan->update([
        'resource_type' => $application->getMorphClass(),
        'resource_id' => $application->id,
        'image_ref' => $application->uuid.':abc123',
        'repo_digest' => null,
        'builds_image' => true,
        'base_image_ref' => $baseImageRef,
        'findings' => $withFindings ? array_map(fn (Finding $finding) => $finding->toArray(), [...candidatesFindings(), $added]) : [],
        'summary' => $withFindings ? $scan->summary : [...$scan->summary, 'eol_distro' => null],
    ]);

    return $scan->fresh();
}

/**
 * The relevant findings of the grype fixture: those the current base image has too.
 *
 * @return list<Finding>
 */
function candidatesRelevantFindings(): array
{
    return (new CandidateEvaluator)->relevant(candidatesFindings(), IgnoreMatcher::fromIgnores(collect()));
}

function candidatesAttributableCount(): int
{
    return count(candidatesRelevantFindings());
}

it('attributes findings to the base image from a registry scan of the current base and recommends the newer tag', function () {
    candidatesFake();
    $scan = candidatesDockerfileScan();
    ResourceScans::refreshSummaries(collect([$scan]));
    $summary = $scan->fresh()->summary;

    RefreshCandidates::run($scan);

    $scan->refresh();
    $base = $scan->candidates['base'];
    $line = $base['entries'][0];

    expect($scan->candidateStatus())->toBe('skipped')
        ->and($scan->candidateEntries())->toBe([])
        ->and($base)->toMatchArray(['image' => 'postgres:15.0-alpine', 'status' => 'ok', 'tags_truncated' => false, 'current_digest' => CANDIDATES_REPO_DIGEST])
        ->and($base['attributable'])->toBe(candidatesAttributableCount())
        ->and($base['attributable_ids'])->not->toContain('CVE-2099-0001')
        ->and($base['attributable_ids'])->toHaveCount(collect(candidatesRelevantFindings())->pluck('id')->unique()->count())
        ->and($base['attributable_ids'])->not->toContain('CVE-2023-2650')
        ->and($base['checked_at'])->not->toBeNull()
        ->and($base['entries'])->toHaveCount(1)
        ->and($line)->toMatchArray(['ref' => 'postgres:15.15-alpine', 'digest' => CANDIDATES_LINE, 'kind' => 'line', 'better' => true, 'left' => ['critical' => 3, 'high' => 32, 'medium' => 0]])
        ->and($line['fixed_ids'])->toContain('CVE-2025-1094')
        ->and($line['fixed_ids'])->not->toContain('CVE-2099-0001')
        ->and(array_sum($line['fixes']))->toBeGreaterThan(0)->toBeLessThanOrEqual($base['attributable'])
        ->and($scan->summary['eol_distro'])->toBe($summary['eol_distro'])
        ->and(candidatesScansToday())->toBe(2)
        ->and(RefreshCandidates::shouldRefresh($scan))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process) => str_contains(candidatesCommand($process), "'tag' 'ls' 'docker.io/library/postgres'"));
    Process::assertRan(fn (PendingProcess $process) => str_contains(candidatesCommand($process), "'registry:postgres@".CANDIDATES_REPO_DIGEST."'")
        && str_contains(candidatesCommand($process), "'--platform' 'linux/amd64'"));
    Process::assertRan(fn (PendingProcess $process) => str_contains(candidatesCommand($process), "'registry:postgres@".CANDIDATES_LINE."'"));
});

it('counts only findings that the current base image has as attributable and fixed', function () {
    candidatesFake();
    $scan = candidatesDockerfileScan();
    // The current base image has 27 of the 50 relevant findings of the app; the line candidate has none of the app's.
    $this->grype[substr(CANDIDATES_REPO_DIGEST, 7)] = 'grype-candidate-15.11';
    $currentBase = (new GrypeReportParser)->parse(file_get_contents(base_path('tests/Fixtures/image-scan/grype-candidate-15.11.ndjson')))['findings'];
    $baseKeys = collect($currentBase)->flatMap(fn (Finding $finding) => [$finding->id, ...$finding->aliases])->map(fn (string $id) => strtolower($id))->flip();
    $attributable = collect(candidatesRelevantFindings())
        ->filter(fn (Finding $finding) => collect([$finding->id, ...$finding->aliases])->contains(fn (string $id) => $baseKeys->has(strtolower($id))));

    RefreshCandidates::run($scan);

    $base = $scan->fresh()->candidates['base'];
    $line = $base['entries'][0];
    expect($attributable)->toHaveCount(27)
        ->and($base['status'])->toBe('ok')
        ->and($base['attributable'])->toBe(27)
        ->and(array_sum($line['fixes']))->toBe(27)
        ->and($line['fixed_ids'])->not->toContain('CVE-2099-0001')
        ->and(collect($line['fixed_ids'])->every(fn (string $id) => $baseKeys->has(strtolower($id))))->toBeTrue();
});

it('never makes base image candidates fix an image that the team does not build', function () {
    candidatesFake();
    $scan = candidatesDockerfileScan();
    RefreshCandidates::run($scan);

    $scan = $scan->fresh();
    $fixedId = $scan->candidates['base']['entries'][0]['fixed_ids'][0];
    $finding = collect(candidatesFindings())->first(fn (Finding $finding) => $finding->id === $fixedId || in_array($fixedId, $finding->aliases, true));
    $scan->builds_image = false;

    expect(SecurityActions::forScan($scan, IgnoreMatcher::forResource($scan->resource))['byFinding'])->not->toHaveKey($finding->id);
});

it('says that no newer base image is better when the line candidate has as many findings as the current base', function () {
    candidatesFake();
    $this->grype[substr(CANDIDATES_LINE, 7)] = 'grype';
    $scan = candidatesDockerfileScan();

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidates['base'])->toMatchArray(['status' => 'no_better', 'attributable' => candidatesAttributableCount()])
        ->and($scan->fresh()->candidates['base']['entries'][0]['better'])->toBeFalse();
});

it('learns which findings come from the base image also without a newer tag', function (bool $sameDigest, string $baseImageRef) {
    candidatesFake();
    if ($sameDigest) {
        $this->digests['15.15-alpine'] = CANDIDATES_REPO_DIGEST;
    } else {
        $this->grype[substr(CANDIDATES_LINE, 7)] = 'grype';
    }
    $scan = candidatesDockerfileScan($baseImageRef);

    RefreshCandidates::run($scan);

    $scan->refresh();
    $actions = SecurityActions::forScan($scan, IgnoreMatcher::forResource($scan->resource));
    expect($scan->candidates['base'])->toMatchArray(['status' => 'no_better', 'entries' => [], 'attributable' => candidatesAttributableCount()])
        ->and($scan->candidates['base']['attributable_ids'])->not->toContain('CVE-2099-0001')
        ->and($actions['byFinding']['CVE-2099-0001']->type)->toBe(SecurityActionType::Rebuild);
})->with([
    'no newer tag in the line' => [false, 'postgres:15.15-alpine'],
    'the newer tag has the same digest' => [true, 'postgres:15.0-alpine'],
]);

it('reuses the SBOM of the current base image without a newer tag', function () {
    candidatesFake();
    $this->digests['15.15-alpine'] = CANDIDATES_REPO_DIGEST;
    $scan = candidatesDockerfileScan();
    ImageSbom::factory()->create(['server_id' => $this->server->id, 'image_id' => CANDIDATES_REPO_DIGEST]);

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidates['base'])->toMatchArray(['status' => 'no_better', 'attributable' => candidatesAttributableCount()])
        ->and(candidatesRan("'registry:"))->toBe(0)
        ->and(candidatesRan('coolify-scan-exit grype'))->toBe(1)
        ->and(candidatesScansToday())->toBe(0);
});

it('looks for no base image candidates without relevant findings or for another build pack', function (bool $withFindings, string $buildPack, ?string $baseImageRef) {
    candidatesFake();
    $scan = candidatesDockerfileScan($baseImageRef ?? 'postgres:15.0-alpine', $withFindings);
    $scan->update(['base_image_ref' => $baseImageRef]);
    $scan->resource->update(['build_pack' => $buildPack]);

    RefreshCandidates::run($scan->fresh());

    expect($scan->fresh()->candidates)->toBeNull();
    Process::assertNothingRan();
})->with([
    'no findings' => [false, 'dockerfile', 'postgres:15.0-alpine'],
    'no base image' => [true, 'dockerfile', null],
    'nixpacks' => [true, 'nixpacks', 'postgres:15.0-alpine'],
]);

it('counts both base image scans toward the daily limit and records the limit', function (int $scansToday, int $registryScans, ?int $attributable) {
    candidatesFake();
    $this->server->settings->forceFill(['image_candidate_scans_today' => $scansToday, 'image_candidate_scans_date' => today()])->saveQuietly();
    $scan = candidatesDockerfileScan();

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidates['base'])->toMatchArray(['status' => 'failed', 'error' => 'daily_limit', 'entries' => [], 'attributable' => $attributable === null ? null : candidatesAttributableCount()])
        ->and(candidatesRan("'registry:"))->toBe($registryScans)
        ->and(candidatesScansToday())->toBe(20);
})->with([
    'limit reached' => [20, 0, null],
    'one scan left for the current base' => [19, 1, 1],
]);

it('reuses the SBOM of the current base image on the server and does not count it', function () {
    candidatesFake();
    $scan = candidatesDockerfileScan();
    ImageSbom::factory()->create(['server_id' => $this->server->id, 'image_id' => CANDIDATES_REPO_DIGEST]);

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidates['base']['status'])->toBe('ok')
        ->and(candidatesRan("'registry:"))->toBe(1)
        ->and(candidatesScansToday())->toBe(1);
});

it('checks the disk space for the app image size before the base image registry scans', function () {
    candidatesFake();
    $this->diskFree = '4000000000 50000000000';
    $scan = candidatesDockerfileScan();

    RefreshCandidates::run($scan);

    expect(candidatesRan("'registry:"))->toBe(0)
        ->and(candidatesRan('docker image inspect'))->toBe(1)
        ->and($scan->fresh()->candidates['base'])->toMatchArray(['status' => 'failed', 'error' => 'disk'])
        ->and(candidatesScansToday())->toBe(0);
});

it('cannot compare a base image without a version tag, but learns which findings come from it', function () {
    candidatesFake();
    $this->digests['latest'] = CANDIDATES_REPO_DIGEST;
    $scan = candidatesDockerfileScan('postgres:latest');

    RefreshCandidates::run($scan);

    $scan->refresh();
    expect($scan->candidates['base'])->toMatchArray([
        'image' => 'postgres:latest', 'status' => 'cannot_compare', 'error' => null, 'entries' => [],
        'attributable' => candidatesAttributableCount(), 'current_digest' => CANDIDATES_REPO_DIGEST,
    ])
        ->and(candidatesRan("'registry:"))->toBe(1)
        ->and(SecurityActions::forScan($scan, IgnoreMatcher::forResource($scan->resource))['byFinding']['CVE-2099-0001']->type)->toBe(SecurityActionType::Rebuild);
});

it('scans a base image pinned by digest without asking the registry for its digest', function () {
    candidatesFake();
    $scan = candidatesDockerfileScan('postgres@'.CANDIDATES_REPO_DIGEST);

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidates['base'])->toMatchArray(['status' => 'cannot_compare', 'attributable' => candidatesAttributableCount(), 'current_digest' => CANDIDATES_REPO_DIGEST])
        ->and(candidatesRan('coolify-scan-exit digest'))->toBe(0);
});

it('leaves the base origin unknown when the base image could not be scanned', function () {
    candidatesFake();
    $scan = candidatesDockerfileScan('postgres:latest');

    RefreshCandidates::run($scan);

    expect($scan->fresh()->candidates['base'])->toMatchArray(['status' => 'failed', 'attributable' => null]);
});

it('leaves the base origin unknown when the scan of the current base image was cut', function () {
    candidatesFake();
    $this->grypeCut = true;
    $scan = candidatesDockerfileScan();

    RefreshCandidates::run($scan);

    $scan->refresh();
    expect($scan->candidates['base'])->toMatchArray(['status' => 'no_better', 'attributable' => null, 'attributable_ids' => []])
        ->and(SecurityActions::forScan($scan, IgnoreMatcher::forResource($scan->resource))['byFinding'])->not->toHaveKey('CVE-2099-0001');
});
