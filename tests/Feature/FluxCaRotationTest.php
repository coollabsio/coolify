<?php

use App\Actions\Node\RepairFluxTrust;
use App\Actions\Sentinel\DistributeFluxTrustBundle;
use App\Actions\Sentinel\IssueFluxCertificate;
use App\Actions\Sentinel\MaterializeFluxCertificate;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Actions\Sentinel\ResolveFluxTrustBundle;
use App\Actions\Sentinel\RotateFluxCertificateAuthority;
use App\Jobs\DistributeFluxTrustBundleJob;
use App\Livewire\Settings\FluxTrust;
use App\Models\FluxCaRotation;
use App\Models\FluxCertificate;
use App\Models\FluxCertificateAuthority;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $this->directory = sys_get_temp_dir().'/coolify-flux-rotation-'.bin2hex(random_bytes(8));
    config()->set('app.env', 'local');
    config()->set('constants.coolify.base_config_path', $this->directory);
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux:7444');
    config()->set('constants.flux.internal_token', 'internal-secret');
    config()->set('constants.flux.public_url', 'http://flux:7443');
    config()->set('constants.flux.development_allow_plaintext', true);
    Queue::fake();

    $this->leaf = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($this->leaf);
    $this->oldAuthority = $this->leaf->certificateAuthority;
    $this->team = User::factory()->create()->teams()->firstOrFail();
    $this->privateKeyId = PrivateKey::factory()->create(['team_id' => $this->team->id])->id;
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function rotationNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'team_id' => test()->team->id,
        'is_usable' => true,
        'flux_trust_bundle_version' => 1,
        // The key factory produces one fixed key, so Nodes share it.
        'private_key_id' => test()->privateKeyId,
        ...$attributes,
    ]);

    return $node;
}

function connectRotationNode(Node $node, array $capabilities = ['trust.bundle.update.v1'], int $trustBundleVersion = 1): void
{
    Cache::put($node->cacheKey(), [
        'status' => 'connected',
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'last_heartbeat_at' => now()->toIso8601String(),
        'trust_bundle_version' => $trustBundleVersion,
        'capabilities' => $capabilities,
    ], now()->addMinutes(5));
}

function fakeFluxTrustUpdates(): void
{
    Http::fake(['http://flux:7444/v1/commands/trust.bundle.update' => fn (HttpRequest $request) => Http::response([
        'command_id' => $request['command_id'],
        'observed_at_unix_ms' => 1,
        'installed_version' => $request['version'],
        'changed' => true,
    ])]);
}

/**
 * @return list<string>
 */
function bundleFingerprints(string $bundle): array
{
    preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bundle, $matches);

    return array_map(fn (string $certificate): string => openssl_x509_fingerprint($certificate, 'sha256'), $matches[0]);
}

function instanceUserWithRole(?string $rootRole): User
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    if ($rootRole !== null) {
        $rootTeam->members()->attach($user->id, ['role' => $rootRole]);
    }

    return $user;
}

function switchRotation(bool $force = false): FluxCaRotation
{
    return RotateFluxCertificateAuthority::make()->advance($force, function (FluxCertificate $certificate): void {});
}

it('starts a rotation with a pending CA and a dual-CA trust bundle', function () {
    $rotation = RotateFluxCertificateAuthority::make()->start();
    $newAuthority = $rotation->toAuthority;
    $bundle = ResolveFluxTrustBundle::run();

    expect($rotation->status)->toBe(FluxCaRotation::STATUS_DISTRIBUTING)
        ->and($rotation->dual_bundle_version)->toBe(2)
        ->and($rotation->from_certificate_authority_id)->toBe($this->oldAuthority->id)
        ->and($newAuthority->state)->toBe(FluxCertificateAuthority::STATE_PENDING)
        ->and($this->oldAuthority->fresh()->state)->toBe(FluxCertificateAuthority::STATE_ACTIVE)
        ->and($bundle['version'])->toBe(2)
        ->and(bundleFingerprints($bundle['certificate_pem']))->toBe([$this->oldAuthority->fingerprint, $newAuthority->fingerprint])
        ->and($bundle['certificate_pem'])->not->toContain('PRIVATE KEY');
    Queue::assertPushed(DistributeFluxTrustBundleJob::class, fn (DistributeFluxTrustBundleJob $job) => $job->nodeId === null);

    expect(fn () => RotateFluxCertificateAuthority::make()->start())
        ->toThrow(RuntimeException::class, 'already in progress');
});

it('delivers the bundle over Flux and records the acknowledged version', function () {
    fakeFluxTrustUpdates();
    $node = rotationNode();
    connectRotationNode($node);
    RotateFluxCertificateAuthority::make()->start();

    expect(DistributeFluxTrustBundle::run($node))->toBe(DistributeFluxTrustBundle::ACKNOWLEDGED)
        ->and($node->fresh()->flux_trust_bundle_version)->toBe(2)
        ->and($node->fresh()->flux_trust_bundle_acknowledged_at)->not->toBeNull()
        ->and(DistributeFluxTrustBundle::run($node->fresh()))->toBe(DistributeFluxTrustBundle::CURRENT);

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer internal-secret')
        && $request['server_id'] === $node->uuid
        && $request['version'] === 2
        && $request['bundle_pem'] === ResolveFluxTrustBundle::run()['certificate_pem']
        && ! str_contains($request->body(), 'PRIVATE KEY'));
});

it('leaves offline Nodes pending and delivers the bundle when they reconnect', function () {
    fakeFluxTrustUpdates();
    $node = rotationNode();
    RotateFluxCertificateAuthority::make()->start();

    expect(DistributeFluxTrustBundle::run($node))->toBe(DistributeFluxTrustBundle::OFFLINE)
        ->and($node->fresh()->flux_trust_bundle_version)->toBe(1);
    Http::assertNothingSent();

    $this->postJson('/api/v1/internal/sentinel/control/events', [
        'event' => 'connected',
        'server_id' => $node->uuid,
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'sentinel_version' => 'main',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'plaintext',
        'capabilities' => ['trust.bundle.update.v1'],
    ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();

    Queue::assertPushed(DistributeFluxTrustBundleJob::class, fn (DistributeFluxTrustBundleJob $job) => $job->nodeId === $node->id);
    (new DistributeFluxTrustBundleJob($node->id))->handle();

    expect($node->fresh()->flux_trust_bundle_version)->toBe(2);
});

it('fans out scheduled retries only to connected Nodes that are behind', function () {
    $behind = rotationNode();
    $offline = rotationNode();
    $current = rotationNode(['flux_trust_bundle_version' => 2]);
    connectRotationNode($behind);
    connectRotationNode($current, trustBundleVersion: 2);
    RotateFluxCertificateAuthority::make()->start();
    Queue::fake();

    (new DistributeFluxTrustBundleJob)->handle();

    Queue::assertPushed(DistributeFluxTrustBundleJob::class, 1);
    Queue::assertPushed(DistributeFluxTrustBundleJob::class, fn (DistributeFluxTrustBundleJob $job) => $job->nodeId === $behind->id);
});

it('records why a Node without the update capability needs an SSH repair', function () {
    Http::fake();
    $node = rotationNode();
    connectRotationNode($node, ['system.ping.v1']);
    RotateFluxCertificateAuthority::make()->start();

    expect(DistributeFluxTrustBundle::run($node))->toBe(DistributeFluxTrustBundle::UNSUPPORTED)
        ->and($node->fresh()->flux_trust_bundle_error)->toContain('repair trust over SSH');
    Http::assertNothingSent();
});

it('records a rejected delivery as a Node error', function () {
    Http::fake(['http://flux:7444/*' => Http::response('Every trust bundle certificate must be a CA certificate.', 502)]);
    $node = rotationNode();
    connectRotationNode($node);
    RotateFluxCertificateAuthority::make()->start();

    expect(DistributeFluxTrustBundle::run($node))->toBe(DistributeFluxTrustBundle::FAILED)
        ->and($node->fresh()->flux_trust_bundle_version)->toBe(1)
        ->and($node->fresh()->flux_trust_bundle_error)->toContain('must be a CA certificate');
});

it('blocks the switch until every usable Node acknowledged the dual bundle unless forced', function () {
    $acknowledged = rotationNode(['name' => 'acknowledged', 'flux_trust_bundle_version' => 2]);
    $offline = rotationNode(['name' => 'offline-node']);
    rotationNode(['name' => 'unusable', 'is_usable' => false]);
    $rotation = RotateFluxCertificateAuthority::make()->start();

    expect(fn () => switchRotation())->toThrow(RuntimeException::class, 'offline-node');
    expect($rotation->fresh()->status)->toBe(FluxCaRotation::STATUS_DISTRIBUTING)
        ->and(FluxCertificate::query()->where('state', 'active')->sole()->id)->toBe($this->leaf->id)
        ->and(RotateFluxCertificateAuthority::make()->status()['blocking'])->toBe(1);

    $switched = switchRotation(force: true);
    $activeLeaf = FluxCertificate::query()->where('state', 'active')->sole();

    expect($switched->status)->toBe(FluxCaRotation::STATUS_SWITCHED)
        ->and($switched->switch_forced)->toBeTrue()
        ->and($activeLeaf->certificate_authority_id)->toBe($rotation->to_certificate_authority_id)
        ->and($activeLeaf->identities)->toBe(['flux.example.test'])
        ->and(openssl_x509_verify($activeLeaf->certificate_pem, $rotation->toAuthority->certificate_pem))->toBe(1)
        ->and(file_get_contents($this->directory.'/flux/pki/ca.pem'))->toBe($rotation->toAuthority->certificate_pem)
        ->and($rotation->toAuthority->fresh()->state)->toBe(FluxCertificateAuthority::STATE_ACTIVE)
        ->and($this->oldAuthority->fresh()->state)->toBe(FluxCertificateAuthority::STATE_SUPERSEDED)
        ->and(ResolveFluxTrustBundle::run()['version'])->toBe(2);
});

it('switches without force after every usable Node acknowledged and renews later leaves with the active CA', function () {
    rotationNode(['flux_trust_bundle_version' => 2]);
    $rotation = RotateFluxCertificateAuthority::make()->start();

    $switched = switchRotation();

    expect($switched->switch_forced)->toBeFalse();
    expect(RenewFluxCertificate::run(fn () => null, true))->toBeTrue();
    expect(FluxCertificate::query()->where('state', 'active')->sole()->certificate_authority_id)->toBe($rotation->to_certificate_authority_id)
        ->and(IssueFluxCertificate::run(['flux.example.test'])->certificate_authority_id)->toBe($rotation->to_certificate_authority_id);
});

it('keeps a failed switch resumable with the previous certificate restored', function () {
    rotationNode(['flux_trust_bundle_version' => 2]);
    $rotation = RotateFluxCertificateAuthority::make()->start();

    expect(fn () => RotateFluxCertificateAuthority::make()->advance(false, function (FluxCertificate $certificate) use ($rotation): void {
        if ($certificate->certificate_authority_id === $rotation->to_certificate_authority_id) {
            throw new RuntimeException('Flux did not serve the expected TLS certificate.');
        }
    }))->toThrow(RuntimeException::class, 'expected TLS certificate');

    expect($rotation->fresh()->status)->toBe(FluxCaRotation::STATUS_DISTRIBUTING)
        ->and($rotation->fresh()->last_error)->toContain('expected TLS certificate')
        ->and(FluxCertificate::query()->where('state', 'active')->sole()->id)->toBe($this->leaf->id)
        ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($this->leaf->certificate_pem)
        ->and($rotation->toAuthority->fresh()->state)->toBe(FluxCertificateAuthority::STATE_PENDING);

    expect(switchRotation()->status)->toBe(FluxCaRotation::STATUS_SWITCHED)
        ->and($rotation->fresh()->last_error)->toBeNull();
});

it('resumes a switch whose new leaf was activated before the switch was recorded', function () {
    rotationNode(['flux_trust_bundle_version' => 2]);
    $rotation = RotateFluxCertificateAuthority::make()->start();
    RenewFluxCertificate::run(fn () => null, true, $rotation->toAuthority);
    $leafCount = FluxCertificate::query()->count();
    $restarts = 0;

    $switched = RotateFluxCertificateAuthority::make()->advance(false, function () use (&$restarts): void {
        $restarts++;
    });

    expect($switched->status)->toBe(FluxCaRotation::STATUS_SWITCHED)
        ->and($restarts)->toBe(0)
        ->and(FluxCertificate::query()->count())->toBe($leafCount);
});

it('retires the old CA after Nodes acknowledge the new-only bundle', function () {
    $node = rotationNode(['flux_trust_bundle_version' => 2]);
    $rotation = RotateFluxCertificateAuthority::make()->start();
    switchRotation();
    Queue::fake();

    $retiring = RotateFluxCertificateAuthority::make()->advance();
    $bundle = ResolveFluxTrustBundle::run();

    expect($retiring->status)->toBe(FluxCaRotation::STATUS_RETIRING)
        ->and($retiring->final_bundle_version)->toBe(3)
        ->and($bundle['version'])->toBe(3)
        ->and(bundleFingerprints($bundle['certificate_pem']))->toBe([$rotation->toAuthority->fingerprint]);
    Queue::assertPushed(DistributeFluxTrustBundleJob::class);
    expect(fn () => RotateFluxCertificateAuthority::make()->advance())->toThrow(RuntimeException::class, $node->name);

    $node->update(['flux_trust_bundle_version' => 3]);
    $completed = RotateFluxCertificateAuthority::make()->advance();
    $retired = $this->oldAuthority->fresh();

    expect($completed->status)->toBe(FluxCaRotation::STATUS_COMPLETED)
        ->and($completed->completion_forced)->toBeFalse()
        ->and($retired->state)->toBe(FluxCertificateAuthority::STATE_RETIRED)
        ->and($retired->private_key_pem)->toBe('')
        ->and($retired->certificate_pem)->toBe($this->oldAuthority->certificate_pem)
        ->and(RotateFluxCertificateAuthority::make()->current())->toBeNull()
        ->and(ResolveFluxTrustBundle::run()['version'])->toBe(3);
    expect(fn () => IssueFluxCertificate::run(['flux.example.test'], $retired))
        ->toThrow(RuntimeException::class, 'retired or discarded');
});

it('retires an old CA whose key was encrypted with a previous application key', function () {
    rotationNode(['flux_trust_bundle_version' => 3]);
    RotateFluxCertificateAuthority::make()->start();
    switchRotation();
    RotateFluxCertificateAuthority::make()->advance();
    $previousEncrypter = new Encrypter(random_bytes(32), 'aes-256-cbc');
    FluxCertificateAuthority::query()->whereKey($this->oldAuthority->id)
        ->update(['private_key_pem' => $previousEncrypter->encryptString('old key')]);

    $completed = RotateFluxCertificateAuthority::make()->advance();
    $retired = $this->oldAuthority->fresh();

    expect($completed->status)->toBe(FluxCaRotation::STATUS_COMPLETED)
        ->and($retired->state)->toBe(FluxCertificateAuthority::STATE_RETIRED)
        ->and($retired->private_key_pem)->toBe('');
});

it('cancels before the switch with an old-only bundle and discards the new CA', function () {
    $node = rotationNode(['flux_trust_bundle_version' => 2]);
    $rotation = RotateFluxCertificateAuthority::make()->start();

    $cancelling = RotateFluxCertificateAuthority::make()->cancel();
    $discarded = $rotation->toAuthority->fresh();
    $bundle = ResolveFluxTrustBundle::run();

    expect($cancelling->status)->toBe(FluxCaRotation::STATUS_CANCELLING)
        ->and($discarded->state)->toBe(FluxCertificateAuthority::STATE_DISCARDED)
        ->and($discarded->private_key_pem)->toBe('')
        ->and($bundle['version'])->toBe(3)
        ->and(bundleFingerprints($bundle['certificate_pem']))->toBe([$this->oldAuthority->fingerprint])
        ->and(FluxCertificate::query()->where('state', 'active')->sole()->id)->toBe($this->leaf->id);
    expect(fn () => RotateFluxCertificateAuthority::make()->cancel())->toThrow(RuntimeException::class);
    expect(fn () => RotateFluxCertificateAuthority::make()->advance())->toThrow(RuntimeException::class, $node->name);

    $cancelled = RotateFluxCertificateAuthority::make()->advance(force: true);
    expect($cancelled->status)->toBe(FluxCaRotation::STATUS_CANCELLED)
        ->and($cancelled->completion_forced)->toBeTrue();

    $next = RotateFluxCertificateAuthority::make()->start();
    expect($next->dual_bundle_version)->toBe(4)
        ->and($next->from_certificate_authority_id)->toBe($this->oldAuthority->id);
});

it('refuses to cancel after the switch', function () {
    rotationNode(['flux_trust_bundle_version' => 2]);
    RotateFluxCertificateAuthority::make()->start();
    switchRotation();

    expect(fn () => RotateFluxCertificateAuthority::make()->cancel())
        ->toThrow(RuntimeException::class, 'Finish the rotation instead');
});

it('repairs trust over SSH with the bundle of the current phase', function () {
    Process::fake();
    $node = rotationNode();
    $installedBundle = function () use ($node): array {
        $bundle = ResolveFluxTrustBundle::run();
        RepairFluxTrust::run($node);
        $script = RepairFluxTrust::repairScript($bundle['certificate_pem'], $bundle['version']);
        Process::assertRan(fn ($process) => str_contains($process->command, base64_encode($script)));

        return [$bundle['version'], bundleFingerprints($bundle['certificate_pem'])];
    };

    expect($installedBundle())->toBe([1, [$this->oldAuthority->fingerprint]])
        ->and($node->fresh()->flux_trust_bundle_version)->toBe(1);

    $rotation = RotateFluxCertificateAuthority::make()->start();
    expect($installedBundle())->toBe([2, [$this->oldAuthority->fingerprint, $rotation->toAuthority->fingerprint]])
        ->and($node->fresh()->flux_trust_bundle_version)->toBe(2);

    switchRotation();
    expect($installedBundle())->toBe([2, [$this->oldAuthority->fingerprint, $rotation->toAuthority->fingerprint]]);

    RotateFluxCertificateAuthority::make()->advance();
    expect($installedBundle())->toBe([3, [$rotation->toAuthority->fingerprint]])
        ->and($node->fresh()->flux_trust_bundle_version)->toBe(3);
});

it('accepts multi-CA bundles and rejects leaf certificates in trust scripts', function () {
    $rotation = RotateFluxCertificateAuthority::make()->start();
    $bundle = ResolveFluxTrustBundle::run()['certificate_pem'];

    expect(RepairFluxTrust::isValidBundle($bundle))->toBeTrue()
        ->and(RepairFluxTrust::isValidBundle($bundle.$this->leaf->certificate_pem))->toBeFalse()
        ->and(RepairFluxTrust::isValidBundle($bundle.$this->leaf->private_key_pem))->toBeFalse()
        ->and(RepairFluxTrust::isValidBundle('garbage'))->toBeFalse()
        ->and(RepairFluxTrust::trustFileStagingScript($bundle, 2))->toContain(base64_encode($bundle));
    expect(fn () => RepairFluxTrust::repairScript($this->leaf->certificate_pem, 2))->toThrow(InvalidArgumentException::class);
});

it('gives each Node an assignment for a bundle that still trusts the Flux leaf', function () {
    $resolver = ResolveFluxTrustBundle::make();
    rotationNode(['flux_trust_bundle_version' => 2]);

    expect($resolver->assignmentVersion(null))->toBe(1)
        ->and($resolver->assignmentVersion(1))->toBe(1);

    RotateFluxCertificateAuthority::make()->start();
    expect($resolver->assignmentVersion(1))->toBe(1)
        ->and($resolver->assignmentVersion(2))->toBe(2)
        ->and($resolver->assignmentVersion(null))->toBe(2)
        ->and($resolver->assignmentVersion(9))->toBe(2);

    switchRotation();
    expect($resolver->assignmentVersion(1))->toBe(2)
        ->and($resolver->assignmentVersion(2))->toBe(2);

    RotateFluxCertificateAuthority::make()->advance();
    expect($resolver->assignmentVersion(2))->toBe(2)
        ->and($resolver->assignmentVersion(3))->toBe(3)
        ->and($resolver->assignmentVersion(1))->toBe(3);
});

it('returns the reported bundle version in assignments and grants the update capability', function () {
    $keyPair = sodium_crypto_sign_seed_keypair(str_repeat('C', 32));
    config()->set('constants.flux.signing_key_id', 'dev-key');
    config()->set('constants.flux.signing_private_key', base64_encode(sodium_crypto_sign_secretkey($keyPair)));
    config()->set('constants.flux.issuer', 'coolify-dev');
    $node = rotationNode();
    $token = $node->ensureValidSentinelToken();
    RotateFluxCertificateAuthority::make()->start();
    $request = fn (array $payload) => $this->postJson('/api/v1/sentinel/control/assignment', [
        'sentinel_version' => 'main',
        'protocol_min' => 1,
        'protocol_max' => 1,
        'capabilities' => ['system.ping.v1', 'trust.bundle.update.v1'],
        ...$payload,
    ], ['Authorization' => 'Bearer '.$token]);

    $request([])->assertOk()
        ->assertJsonPath('trust_bundle_version', 1);
    expect($request(['trust_bundle_version' => 2])->assertOk()->json('trust_bundle_version'))->toBe(2);
    $request(['trust_bundle_version' => 0])->assertUnprocessable();

    $credential = $request([])->json('credential');
    $claims = json_decode(base64_decode(strtr(explode('.', $credential)[1], '-_', '+/')), true);
    expect($claims['caps'])->toContain('trust.bundle.update.v1');
});

describe('rotation command', function () {
    it('starts, reports, blocks, forces, and cancels a rotation', function () {
        rotationNode(['name' => 'lagging-node']);

        $this->artisan('flux:rotate-ca')
            ->expectsOutputToContain('Flux CA rotation started')
            ->assertSuccessful();
        $this->artisan('flux:rotate-ca --status')
            ->expectsOutputToContain('distributing')
            ->assertSuccessful();
        $this->artisan('flux:rotate-ca')
            ->expectsOutputToContain('already in progress')
            ->assertFailed();
        $this->artisan('flux:rotate-ca --continue')
            ->expectsOutputToContain('lagging-node')
            ->assertFailed();
        $this->artisan('flux:rotate-ca --force')->assertExitCode(2);
        $this->artisan('flux:rotate-ca --cancel')
            ->expectsOutputToContain('cancelled')
            ->assertSuccessful();
        $this->artisan('flux:rotate-ca --continue --force')
            ->expectsOutputToContain('cancelled')
            ->assertSuccessful();

        expect(FluxCaRotation::query()->sole()->status)->toBe(FluxCaRotation::STATUS_CANCELLED);
    });

    it('does nothing while host-native Sentinel is disabled', function () {
        config()->set('constants.sentinel.host_enabled', false);

        $this->artisan('flux:rotate-ca')->assertFailed();
        expect(FluxCaRotation::query()->count())->toBe(0);
    });
});

describe('rotation settings page', function () {
    it('lets instance admins start, advance, and cancel a rotation', function (string $role) {
        $user = instanceUserWithRole($role);
        $node = rotationNode(['name' => 'visible-node']);
        $this->actingAs($user);
        session(['currentTeam' => ['id' => 0]]);

        Livewire::test(FluxTrust::class)
            ->assertSee('visible-node')
            ->assertDontSee('PRIVATE KEY')
            ->call('startRotation')
            ->assertDispatched('success')
            ->call('continueRotation')
            ->assertDispatched('error')
            ->call('cancelRotation')
            ->assertSet('status.rotation.status', FluxCaRotation::STATUS_CANCELLING);

        expect(FluxCaRotation::query()->sole()->status)->toBe(FluxCaRotation::STATUS_CANCELLING);
    })->with(['owner', 'admin']);

    it('denies root team members and admins of other teams', function (?string $rootRole) {
        $user = instanceUserWithRole($rootRole);
        $this->team->members()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);
        session(['currentTeam' => ['id' => $this->team->id]]);

        Livewire::test(FluxTrust::class)->assertForbidden();
        expect(FluxCaRotation::query()->count())->toBe(0);
    })->with([
        'root team member' => ['member'],
        'other team owner' => [null],
    ]);

    it('denies rotation actions after access is lost', function () {
        $user = instanceUserWithRole('admin');
        $this->actingAs($user);
        session(['currentTeam' => ['id' => 0]]);
        $component = Livewire::test(FluxTrust::class);

        Team::find(0)->members()->updateExistingPivot($user->id, ['role' => 'member']);
        $user->unsetRelation('teams');

        $component->call('startRotation')->assertForbidden();
        expect(FluxCaRotation::query()->count())->toBe(0);
    });

    it('is not available outside development', function () {
        config()->set('app.env', 'production');
        $this->actingAs(instanceUserWithRole('owner'));
        session(['currentTeam' => ['id' => 0]]);

        Livewire::test(FluxTrust::class)->assertNotFound();
    });
});
