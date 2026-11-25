<?php

use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

/**
 * Create a PrivateKey without firing model events. The PrivateKey `saving`
 * hook validates/fingerprints real key material and the `saved` hook writes
 * to the filesystem — neither is wanted in a unit test. Skipping events also
 * skips BaseModel's uuid generation, so the uuid is set explicitly here (it
 * is not in $fillable, so it cannot go through mass assignment).
 */
function makePrivateKey(string $name, string $material, string $fingerprint, int $teamId): PrivateKey
{
    return PrivateKey::withoutEvents(function () use ($name, $material, $fingerprint, $teamId) {
        $key = new PrivateKey([
            'name' => $name,
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n{$material}\n-----END OPENSSH PRIVATE KEY-----",
            'fingerprint' => $fingerprint,
            'team_id' => $teamId,
        ]);
        $key->uuid = (string) new Cuid2;
        $key->save();

        return $key;
    });
}

beforeEach(function () {
    // handleError() turns a ModelNotFoundException into abort(404); rendering the 404
    // page reads InstanceSettings::get(), which findOrFail(0)s. Seed the singleton row.
    // `id` is not in $fillable, so it must be set outside of mass assignment.
    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    // Team A — the attacker
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->teamA->members()->attach($this->userA->id, ['role' => 'owner']);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);
    $this->applicationA = Application::factory()->create([
        'environment_id' => $this->environmentA->id,
        'private_key_id' => null,
        'source_id' => null,
        'source_type' => null,
    ]);

    // Team B — the victim (holds the secrets we are trying to steal)
    $this->teamB = Team::factory()->create();

    $this->victimPrivateKey = makePrivateKey('victim-ssh-key', 'VICTIM_KEY_MATERIAL', 'victim-fingerprint', $this->teamB->id);

    $this->victimGithubApp = GithubApp::create([
        'name' => 'victim-github-app',
        'team_id' => $this->teamB->id,
        'private_key_id' => $this->victimPrivateKey->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
    ]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('setPrivateKey rejects a PrivateKey owned by another team (GHSA-xrvp-4pp4-8rrw)', function () {
    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('setPrivateKey', $this->victimPrivateKey->id);

    $this->applicationA->refresh();
    expect($this->applicationA->private_key_id)->not->toBe($this->victimPrivateKey->id);
    expect($this->applicationA->private_key_id)->toBeNull();
});

test('setPrivateKey accepts a PrivateKey owned by the current team', function () {
    $ownKey = makePrivateKey('own-ssh-key', 'OWN_KEY_MATERIAL', 'own-fingerprint', $this->teamA->id);

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('setPrivateKey', $ownKey->id);

    $this->applicationA->refresh();
    expect($this->applicationA->private_key_id)->toBe($ownKey->id);
});

test('changeSource rejects a GithubApp owned by another team (GHSA-xrvp-4pp4-8rrw)', function () {
    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('changeSource', $this->victimGithubApp->id, GithubApp::class);

    $this->applicationA->refresh();
    expect($this->applicationA->source_id)->not->toBe($this->victimGithubApp->id);
    expect($this->applicationA->source_type)->not->toBe(GithubApp::class);
});

test('changeSource rejects an arbitrary class as source_type', function () {
    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('changeSource', $this->victimGithubApp->id, Server::class);

    $this->applicationA->refresh();
    expect($this->applicationA->source_type)->not->toBe(Server::class);
});

test('changeSource dispatches configuration changed for an owned source', function () {
    Http::fake([
        'https://api.github.com/repos/*' => Http::response(['id' => 123]),
    ]);
    $source = GithubApp::create([
        'name' => 'own-github-app',
        'team_id' => $this->teamA->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
    ]);
    $this->applicationA->update(['git_repository' => 'coollabsio/coolify']);

    Livewire::test(Source::class, ['application' => $this->applicationA->fresh()])
        ->call('changeSource', $source->id, GithubApp::class)
        ->assertDispatched('configurationChanged');
});

test('changeSource dispatches configuration changed when repository metadata lookup fails after persistence', function () {
    Http::fake([
        'https://api.github.com/repos/*' => Http::response(
            ['message' => 'Unavailable'],
            503,
            ['X-RateLimit-Reset' => now()->addMinute()->timestamp],
        ),
    ]);
    $source = GithubApp::create([
        'name' => 'own-unavailable-github-app',
        'team_id' => $this->teamA->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
    ]);
    $this->applicationA->update(['git_repository' => 'coollabsio/coolify']);

    Livewire::test(Source::class, ['application' => $this->applicationA->fresh()])
        ->call('changeSource', $source->id, GithubApp::class)
        ->assertDispatched('configurationChanged');

    expect($this->applicationA->refresh()->source_id)->toBe($source->id);
});

test('privateKeyId is locked so submit() cannot persist a client-supplied foreign id', function () {
    // Without #[Locked], an attacker could POST {"updates": {"privateKeyId": <foreign_id>},
    // "calls": [{"method": "submit"}]} and have syncData(true) write the foreign id through
    // Application::update(['private_key_id' => $this->privateKeyId]) — bypassing setPrivateKey()
    // and its team-scoped lookup entirely. Locking the property closes that path at the wire layer.
    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->set('privateKeyId', $this->victimPrivateKey->id);
})->throws(CannotUpdateLockedPropertyException::class);

describe('after the user switches the session team', function () {
    beforeEach(function () {
        Http::fake([
            'https://api.github.com/repos/*' => Http::response(['id' => 123]),
        ]);
        $this->teamB->members()->attach($this->userA->id, ['role' => 'owner']);
        $this->applicationA->update(['git_repository' => 'coollabsio/coolify']);

        $this->ownKey = makePrivateKey('own-ssh-key', 'OWN_KEY_MATERIAL', 'own-fingerprint', $this->teamA->id);
        $this->ownGithubApp = GithubApp::create([
            'name' => 'own-github-app',
            'team_id' => $this->teamA->id,
            'app_id' => 1,
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => false,
        ]);
        $this->victimGithubApp->update(['app_id' => 2]);
        $this->systemWideGithubApp = GithubApp::create([
            'name' => 'system-wide-github-app',
            'team_id' => Team::factory()->create()->id,
            'app_id' => 3,
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => false,
            'is_system_wide' => true,
        ]);

        $this->component = Livewire::test(Source::class, ['application' => $this->applicationA->fresh()]);
        session(['currentTeam' => $this->teamB]);
    });

    test('lists only keys and sources of the application team and system-wide sources', function () {
        $component = Livewire::test(Source::class, ['application' => $this->applicationA->fresh()]);

        expect($component->get('privateKeys')->pluck('id')->all())->toBe([$this->ownKey->id])
            ->and($component->get('sources')->pluck('id')->sort()->values()->all())
            ->toBe([$this->ownGithubApp->id, $this->systemWideGithubApp->id]);
    });

    test('setPrivateKey rejects a key of the session team', function () {
        $this->component->call('setPrivateKey', $this->victimPrivateKey->id);

        expect($this->applicationA->refresh()->private_key_id)->toBeNull();
    });

    test('setPrivateKey accepts a key of the application team', function () {
        $this->component->call('setPrivateKey', $this->ownKey->id);

        expect($this->applicationA->refresh()->private_key_id)->toBe($this->ownKey->id);
    });

    test('changeSource rejects a source of the session team', function () {
        try {
            $this->component->call('changeSource', $this->victimGithubApp->id, GithubApp::class);
        } catch (Throwable $e) {
        }

        expect($this->applicationA->refresh()->source_id)->toBeNull();
    });

    test('changeSource accepts a source of the application team', function () {
        $this->component->call('changeSource', $this->ownGithubApp->id, GithubApp::class);

        expect($this->applicationA->refresh()->source_id)->toBe($this->ownGithubApp->id);
    });

    test('changeSource accepts a system-wide source', function () {
        $this->component->call('changeSource', $this->systemWideGithubApp->id, GithubApp::class);

        expect($this->applicationA->refresh()->source_id)->toBe($this->systemWideGithubApp->id);
    });
});

function applicationSourceGiteaAppForTeam(int $teamId, string $name): GiteaApp
{
    // Http::GitSource() pins DNS before Http::fake() answers, so use an IP literal.
    return GiteaApp::create([
        'name' => $name,
        'team_id' => $teamId,
        'api_url' => 'https://1.1.1.1/api/v1',
        'html_url' => 'https://1.1.1.1',
        'access_token' => 'gitea-access-token',
        'refresh_token' => 'gitea-refresh-token',
        'expires_at' => time() + 3600,
        'is_public' => false,
    ]);
}

test('changeSource switches to an owned GiteaApp and stores the Gitea repository id', function () {
    Http::fake([
        '1.1.1.1/api/v1/repos/acme/web' => Http::response(['id' => 314, 'full_name' => 'acme/web']),
    ]);
    $source = applicationSourceGiteaAppForTeam($this->teamA->id, 'own-gitea-app');
    $this->applicationA->update(['git_repository' => 'acme/web']);

    Livewire::test(Source::class, ['application' => $this->applicationA->fresh()])
        ->call('changeSource', $source->id, GiteaApp::class)
        ->assertDispatched('configurationChanged');

    $this->applicationA->refresh();
    expect($this->applicationA->source_type)->toBe(GiteaApp::class)
        ->and($this->applicationA->source_id)->toBe($source->id)
        ->and($this->applicationA->repository_project_id)->toBe(314);
});

test('changeSource rejects a GiteaApp owned by another team', function () {
    Http::fake();
    $victimSource = applicationSourceGiteaAppForTeam($this->teamB->id, 'victim-gitea-app');

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('changeSource', $victimSource->id, GiteaApp::class);

    $this->applicationA->refresh();
    expect($this->applicationA->source_type)->not->toBe(GiteaApp::class);
    Http::assertNothingSent();
});

test('submit reports invalid SSH known hosts as a field error without saving', function () {
    $originalKnownHosts = $this->applicationA->git_ssh_known_hosts;

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->set('gitSshKnownHosts', 'invalid-host-key')
        ->call('submit')
        ->assertHasErrors(['gitSshKnownHosts'])
        ->assertDispatched('known-hosts-invalid')
        ->assertNotDispatched('configurationChanged');

    expect($this->applicationA->fresh()->git_ssh_known_hosts)->toBe($originalKnownHosts);
});

test('submit reports manual host keys of a pinned Git provider as a field error without saving', function (string $line) {
    $this->applicationA->update(['git_ssh_known_hosts' => 'git.example.com ssh-ed25519 AAAA']);

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->set('gitSshKnownHosts', "git.example.com ssh-ed25519 AAAA\n{$line}")
        ->call('submit')
        ->assertHasErrors(['gitSshKnownHosts'])
        ->assertDispatched('known-hosts-invalid')
        ->assertNotDispatched('configurationChanged');

    expect($this->applicationA->fresh()->git_ssh_known_hosts)->toBe('git.example.com ssh-ed25519 AAAA');
})->with([
    'github.com' => 'github.com ssh-ed25519 BBBB',
    'bitbucket.org with a port' => '[altssh.bitbucket.org]:443 ssh-ed25519 BBBB',
    'ssh.github.com on port 443' => '[ssh.github.com]:443 ssh-rsa BBBB',
    'bitbucket.org in a host list' => 'git.example.com,BITBUCKET.ORG ssh-ed25519 BBBB',
]);

test('saving the source form does not reject pinned provider host keys that were already saved', function () {
    Application::query()->whereKey($this->applicationA->id)->update(['git_ssh_known_hosts' => "git.example.com ssh-ed25519 AAAA\ngithub.com ssh-ed25519 BBBB"]);
    $this->applicationA->refresh();

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->set('gitBranch', 'other-branch')
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->applicationA->fresh()->git_branch)->toBe('other-branch');
});

test('saving the source form keeps host keys that a deployment saved after the page opened', function () {
    $component = Livewire::test(Source::class, ['application' => $this->applicationA]);
    Application::query()->whereKey($this->applicationA->id)->update(['git_ssh_known_hosts' => 'git.example.com ssh-ed25519 AAAA']);

    $component->set('gitBranch', 'other-branch')->call('submit')->assertHasNoErrors();

    expect($this->applicationA->fresh())
        ->git_branch->toBe('other-branch')
        ->git_ssh_known_hosts->toBe('git.example.com ssh-ed25519 AAAA');
});

test('saving the source form stores host keys that the user edited', function () {
    $component = Livewire::test(Source::class, ['application' => $this->applicationA]);
    Application::query()->whereKey($this->applicationA->id)->update(['git_ssh_known_hosts' => 'git.example.com ssh-ed25519 AAAA']);

    $component->set('gitSshKnownHosts', 'other.example.com ssh-ed25519 BBBB')->call('submit')->assertHasNoErrors();

    expect($this->applicationA->fresh()->git_ssh_known_hosts)->toBe('other.example.com ssh-ed25519 BBBB');
});

test('removing a host key keeps another host that has the same key', function () {
    $this->applicationA->update(['git_ssh_known_hosts' => "git.example.com ssh-ed25519 AAAA\n[git.example.com]:2222 ssh-ed25519 AAAA"]);
    $component = Livewire::test(Source::class, ['application' => $this->applicationA]);
    $keys = $component->instance()->knownHostFingerprints();

    expect(array_unique(array_column($keys, 'id')))->toHaveCount(2);

    $component->call('removeHostKey', $keys[1]['id']);

    expect($this->applicationA->fresh()->git_ssh_known_hosts)->toBe('git.example.com ssh-ed25519 AAAA');
});

test('a member or a user of another team cannot fetch, accept, or remove host keys', function (string $role) {
    $saved = 'git.example.com ssh-ed25519 AAAA';
    $this->applicationA->update(['git_repository' => 'git@git.example.com:org/repo.git', 'git_ssh_known_hosts' => $saved]);
    $user = User::factory()->create();
    $team = $role === 'member' ? $this->teamA : $this->teamB;
    $team->members()->attach($user->id, ['role' => $role === 'member' ? 'member' : 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $component = Livewire::test(Source::class, ['application' => $this->applicationA]);
    $component->call('fetchHostKeys')->assertDispatched('error')->assertSet('fetchedHostKeys', []);
    $component->call('acceptFetchedHostKeys')->assertDispatched('error');
    $component->call('removeHostKey', sha1($saved))->assertDispatched('error');

    expect($this->applicationA->fresh()->git_ssh_known_hosts)->toBe($saved);
})->with(['member', 'other team owner']);

test('the ssh host keys section shows only when git uses ssh or keys are saved', function (string $repository, ?string $saved, bool $shown) {
    $this->applicationA->update(['git_repository' => $repository, 'git_ssh_known_hosts' => $saved]);

    $component = Livewire::test(Source::class, ['application' => $this->applicationA]);

    $shown ? $component->assertSee('SSH host keys') : $component->assertDontSee('SSH host keys');
})->with([
    'https repository' => ['https://github.com/coollabsio/coolify-examples', null, false],
    'ssh repository' => ['git@git.example.com:org/repo.git', null, true],
    'https repository with saved submodule keys' => ['https://github.com/coollabsio/coolify-examples', 'git.example.com ssh-ed25519 AAAA', true],
]);

test('fetch reads the published host keys of GitHub and Bitbucket for deployments', function () {
    $this->applicationA->update(['git_repository' => 'git@github.com:org/repo.git']);
    Http::fake([
        'api.github.com/meta' => Http::response(['ssh_keys' => ['ssh-ed25519 AAAANEWGITHUB']]),
    ]);

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('fetchHostKeys')
        ->assertDispatched('success')
        ->assertSet('fetchedHostKeys', []);

    $keys = gitProviderHostKeys();
    expect($keys)->toContain('github.com,ssh.github.com,[ssh.github.com]:443 ssh-ed25519 AAAANEWGITHUB')
        ->and(collect($keys)->filter(fn (string $line) => str_starts_with($line, 'github.com,')))->toHaveCount(1)
        ->and(collect($keys)->filter(fn (string $line) => str_starts_with($line, 'bitbucket.org,')))->toHaveCount(3)
        ->and(gitSshSetupCommand('app'))->toContain(base64_encode(implode("\n", $keys)."\n"));
});

test('fetch keeps the built-in host keys when the provider sends no valid keys', function (mixed $response) {
    $this->applicationA->update(['git_repository' => 'git@bitbucket.org:org/repo.git']);
    Http::fake(['bitbucket.org/site/ssh' => $response]);

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('fetchHostKeys')
        ->assertDispatched('error');

    expect(gitProviderHostKeys())->toBe(GIT_PINNED_HOST_KEYS);
})->with([
    'server error' => fn () => Http::response('', 500),
    'only other hosts' => fn () => Http::response("ssh.bitbucket.org ssh-ed25519 AAAAOTHER\n"),
]);

test('the ssh host keys section lists the published keys of a GitHub repository without a remove button', function () {
    $this->applicationA->update(['git_repository' => 'git@github.com:org/repo.git', 'git_ssh_known_hosts' => null]);
    $published = gitKnownHostFingerprint(GIT_PINNED_HOST_KEYS[0]);

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->assertSee('Published by the provider, used for every deployment')
        ->assertSee($published['fingerprint'])
        ->assertDontSee('No saved host keys')
        ->assertDontSee('Remove host key of github.com');
});
