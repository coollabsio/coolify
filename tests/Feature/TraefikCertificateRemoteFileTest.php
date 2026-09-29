<?php

use App\Actions\Development\SeedDevelopmentTraefikCertificates;
use App\Actions\Proxy\DeleteTraefikCertificate;
use App\Actions\Proxy\GetTraefikCertificates;
use App\Enums\ProxyTypes;
use App\Livewire\Server\Proxy;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\TraefikAcmeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $key->id,
        'user' => 'deploy',
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running'],
    ]);

    // Large enough that the old inline write exceeded the 128 KiB single-argument limit.
    $certificates = collect(range(1, 40))->map(fn (int $number): array => [
        'domain' => ['main' => "app{$number}.example.com"],
        'certificate' => base64_encode(str_repeat("certificate-{$number}", 400)),
        'key' => base64_encode(str_repeat("key-{$number}", 150)),
    ])->all();
    $this->acmeContents = json_encode(['letsencrypt' => ['Certificates' => $certificates]]);

    $this->uploadedContents = null;
    Process::fake(function ($process) {
        $command = $process->command;
        if (str_starts_with($command, 'timeout') && str_contains($command, ' scp ')
            && preg_match("~'(".preg_quote(sys_get_temp_dir(), '~')."/coolify-acme-[^']+)'~", $command, $matches)) {
            $this->uploadedContents = file_get_contents($matches[1]);

            return Process::result();
        }

        return Process::result(output: str_contains($command, 'head -c') ? base64_encode($this->acmeContents) : '');
    });
});

it('reads the ACME file with sudo on non-root servers', function () {
    $certificates = GetTraefikCertificates::run($this->server);

    expect($certificates)->toHaveCount(40);
    Process::assertRan(fn ($process) => str_contains($process->command, "\nsudo bash -c 'sh -c '\\''if [ ! -f")
        && str_contains($process->command, 'head -c'));
});

it('uploads a large ACME file instead of passing it as a shell argument', function () {
    $certificate = GetTraefikCertificates::run($this->server)[0];

    DeleteTraefikCertificate::run($this->server, $certificate['id']);

    $remaining = json_decode($this->uploadedContents, true)['letsencrypt']['Certificates'];
    expect(strlen($this->acmeContents))->toBeGreaterThan(128 * 1024)
        ->and($remaining)->toHaveCount(39)
        ->and(collect($remaining)->pluck('domain.main'))->not->toContain('app1.example.com');

    Process::assertRan(fn ($process) => str_contains($process->command, "\nsudo bash -c 'sh -c '\\''set -e;")
        && str_contains($process->command, 'umask 077')
        && str_contains($process->command, 'chmod 600')
        && str_contains($process->command, 'mv --'));
    Process::assertDidntRun(fn ($process) => strlen($process->command) > 128 * 1024);
    expect(glob(sys_get_temp_dir().'/coolify-acme-*'))->toBeEmpty();
});

it('asks for a proxy restart after a certificate is deleted until the proxy is restarted', function () {
    expect($this->server->hasPendingProxyConfiguration())->toBeFalse();

    DeleteTraefikCertificate::run($this->server, GetTraefikCertificates::run($this->server)[0]['id']);

    expect($this->server->fresh()->hasPendingProxyConfiguration())->toBeTrue();

    $this->server->markProxyConfigurationApplied('services: {}');

    expect($this->server->fresh()->hasPendingProxyConfiguration())->toBeFalse()
        ->and($this->server->fresh()->proxy->get('last_applied_settings'))->toBe(md5(base64_encode('services: {}')));
});

it('shows the restart warning after deleting a certificate from the proxy page', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $user = User::factory()->create();
    $user->teams()->attach($this->server->team_id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => $this->server->team]);
    $certificateId = GetTraefikCertificates::run($this->server)[0]['id'];

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->assertDontSee('Restart the proxy to stop serving deleted TLS certificates.')
        ->call('deleteTraefikCertificate', $certificateId)
        ->assertDispatched('refreshServerShow')
        ->assertSee('Restart the proxy to stop serving deleted TLS certificates.');
});

function actingAsTraefikCertificateUser(Team $team, string $role): User
{
    InstanceSettings::forceCreate(['id' => 0]);
    $user = User::factory()->create();
    $user->teams()->attach($team->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

it('lets an admin delete a certificate and records an audit event', function () {
    $this->withoutDefer();
    actingAsTraefikCertificateUser($this->server->team, 'admin');
    $certificate = GetTraefikCertificates::run($this->server)[0];

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('deleteTraefikCertificate', $certificate['id'])
        ->assertDispatched('success');

    $event = AuditEvent::query()->where('event', 'ui.proxy.certificate_deleted')->sole();
    expect($this->uploadedContents)->not->toBeNull()
        ->and($event->team_id)->toBe($this->server->team_id)
        ->and($event->metadata)->toMatchArray([
            'server_uuid' => $this->server->uuid,
            'domain' => 'app1.example.com',
            'resolver' => 'letsencrypt',
        ]);
});

it('lets a member list certificates but not delete them', function () {
    actingAsTraefikCertificateUser($this->server->team, 'member');
    $certificateId = GetTraefikCertificates::run($this->server)[0]['id'];

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('loadTraefikCertificates')
        ->assertSet('traefikCertificates', fn (array $certificates): bool => count($certificates) === 40)
        ->assertDontSeeHtml('submitAction="deleteTraefikCertificate')
        ->call('deleteTraefikCertificate', $certificateId)
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->uploadedContents)->toBeNull()
        ->and($this->server->fresh()->hasPendingProxyConfiguration())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'ui.proxy.certificate_deleted')->exists())->toBeFalse();
});

it('does not show or delete certificates of another team', function () {
    actingAsTraefikCertificateUser(Team::factory()->create(), 'owner');
    $certificateId = app(TraefikAcmeService::class)->certificates($this->acmeContents)[0]['id'];

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('loadTraefikCertificates')
        ->assertSet('traefikCertificates', [])
        ->assertDontSee('app1.example.com')
        ->call('deleteTraefikCertificate', $certificateId)
        ->assertDispatched('error');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'acme.json'));
    expect($this->uploadedContents)->toBeNull()
        ->and($this->server->fresh()->hasPendingProxyConfiguration())->toBeFalse();
});

it('adds example certificates in development without touching other resolvers', function () {
    config(['app.env' => 'local']);

    $this->artisan('dev:traefik-certificates', ['server' => $this->server->id])->assertSuccessful();

    $certificates = collect(app(TraefikAcmeService::class)->certificates($this->uploadedContents));
    $examples = $certificates->where('resolver', SeedDevelopmentTraefikCertificates::RESOLVER);
    expect($certificates->where('resolver', 'letsencrypt'))->toHaveCount(40)
        ->and($examples->pluck('main_domain')->all())->toContain('app.coolify.test', '*.preview.coolify.test')
        ->and($examples->pluck('expires_at')->filter())->toHaveCount($examples->count());
});

it('refuses to add example certificates outside development', function () {
    $this->artisan('dev:traefik-certificates', ['server' => $this->server->id])->assertFailed();

    expect($this->uploadedContents)->toBeNull();
});
