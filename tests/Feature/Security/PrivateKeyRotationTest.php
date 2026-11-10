<?php

use App\Actions\Server\AuthorizedKeys;
use App\Jobs\RemoveRotatedAuthorizedKeyJob;
use App\Jobs\RotatePrivateKeyJob;
use App\Livewire\Security\PrivateKey\Rotate;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Once::flush();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);
    Queue::fake([RemoveRotatedAuthorizedKeyJob::class]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->oldKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $this->newKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id, 'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    $this->serverA = Server::factory()->create([
        'team_id' => $this->team->id, 'private_key_id' => $this->oldKey->id, 'ip' => '192.0.2.10', 'user' => 'root',
    ]);
    $this->serverB = Server::factory()->create([
        'team_id' => $this->team->id, 'private_key_id' => $this->oldKey->id, 'ip' => '192.0.2.11', 'user' => 'root',
    ]);
    // Different host keys: these are two machines.
    $this->serverA->settings->forceFill(['ssh_host_key' => 'ssh-ed25519 AAAAHOSTA'])->save();
    $this->serverB->settings->forceFill(['ssh_host_key' => 'ssh-ed25519 AAAAHOSTB'])->save();

    // Records every SSH command. The login test with the new key fails on the hosts in $this->rejectNewKeyOn.
    // An edit of authorized_keys exits with the code in $this->addExitCode.
    $this->commands = [];
    $this->rejectNewKeyOn = [];
    $this->addExitCode = 0;
    Process::fake(function (PendingProcess $process) {
        $command = $process->command."\n".$process->input;
        $this->commands[] = $command;
        if ($this->addExitCode && str_contains($command, 'grep -qxF')) {
            return Process::result(errorOutput: 'error', exitCode: $this->addExitCode);
        }
        foreach ($this->rejectNewKeyOn as $ip) {
            if (str_contains($command, $ip) && str_contains($command, "ssh_key@{$this->newKey->uuid}") && str_contains($command, 'ls /')) {
                return Process::result(errorOutput: 'Permission denied (publickey).', exitCode: 1);
            }
        }

        return Process::result();
    });
});

afterEach(function () {
    Server::flushIdentityMap();
});

function rotationRemovedKeyOn(array $commands, string $ip, PrivateKey $key, string $grep = 'grep -vF'): bool
{
    return collect($commands)->contains(fn (string $command) => str_contains($command, $ip)
        && str_contains($command, $grep)
        && str_contains($command, $key->publicKeyBody()));
}

it('moves every server to the new key and removes the old key after a grace period', function () {
    $this->serverB->settings->forceFill(['dynamic_timeout' => 7200])->saveQuietly();

    (new RotatePrivateKeyJob($this->oldKey->id, $this->newKey->id))->handle();

    $state = Cache::get(RotatePrivateKeyJob::cacheKey($this->oldKey));
    expect($this->serverA->fresh()->private_key_id)->toBe($this->newKey->id)
        ->and($this->serverB->fresh()->private_key_id)->toBe($this->newKey->id)
        ->and($state['status'])->toBe('finished')
        ->and(collect($state['servers'])->pluck('status')->all())->toBe(['moved_old_pending', 'moved_old_pending'])
        ->and($state['servers'][$this->serverA->uuid]['message'])->toContain('removed from the server in 1 hour 5 minutes')
        ->and(rotationRemovedKeyOn($this->commands, '192.0.2.10', $this->oldKey))->toBeFalse()
        ->and(rotationRemovedKeyOn($this->commands, '192.0.2.11', $this->oldKey))->toBeFalse();
    // Deployments that started before the rotation run until the deployment timeout of the server.
    Queue::assertPushed(RemoveRotatedAuthorizedKeyJob::class, fn ($job) => $job->serverId === $this->serverA->id
        && $job->delay === 3900 && $job->oldPublicKeyBody === $this->oldKey->publicKeyBody());
    Queue::assertPushed(RemoveRotatedAuthorizedKeyJob::class, fn ($job) => $job->serverId === $this->serverB->id && $job->delay === 7500);
});

it('labels a missing old key and a connection failure differently', function (int $exitCode, string $status, string $message) {
    $this->addExitCode = $exitCode;

    (new RotatePrivateKeyJob($this->oldKey->id, $this->newKey->id))->handle();

    $result = Cache::get(RotatePrivateKeyJob::cacheKey($this->oldKey))['servers'][$this->serverA->uuid];
    expect($result['status'])->toBe($status)
        ->and($result['message'])->toContain($message)
        ->and($this->serverA->fresh()->private_key_id)->toBe($this->oldKey->id);
})->with([
    'old key not found' => [AuthorizedKeys::OLD_KEY_NOT_FOUND, 'old_key_not_found', 'The old key is not in ~/.ssh/authorized_keys'],
    'ssh failure' => [255, 'unreachable', 'Could not connect'],
]);

describe('removal of the old key', function () {
    beforeEach(function () {
        $this->serverA->privateKey()->associate($this->newKey)->save();
        Cache::put(RotatePrivateKeyJob::cacheKey($this->oldKey), [
            'status' => 'finished', 'new_private_key_id' => $this->newKey->id,
            'servers' => [$this->serverA->uuid => ['name' => 'alpha', 'status' => 'moved_old_pending', 'message' => 'pending']],
        ]);
        $this->removal = new RemoveRotatedAuthorizedKeyJob($this->serverA->id, $this->oldKey->id, $this->oldKey->uuid, $this->oldKey->publicKeyBody());
        $this->commands = [];
    });

    it('removes the old key and updates the result', function () {
        $this->removal->handle();

        expect(rotationRemovedKeyOn($this->commands, '192.0.2.10', $this->oldKey))->toBeTrue()
            ->and(Cache::get(RotatePrivateKeyJob::cacheKey($this->oldKey))['servers'][$this->serverA->uuid]['status'])->toBe('moved');
    });

    it('removes the old key after its record was deleted', function () {
        $this->serverB->privateKey()->associate($this->newKey)->save();
        $this->oldKey->delete();

        $this->removal->handle();

        expect(rotationRemovedKeyOn($this->commands, '192.0.2.10', $this->oldKey))->toBeTrue();
    });

    it('keeps the old key when the server uses it again', function () {
        $this->serverA->privateKey()->associate($this->oldKey)->save();

        $this->removal->handle();

        $result = Cache::get(RotatePrivateKeyJob::cacheKey($this->oldKey))['servers'][$this->serverA->uuid];
        expect(collect($this->commands)->filter(fn ($command) => str_contains($command, 'authorized_keys')))->toBeEmpty()
            ->and($result['status'])->toBe('moved_old_not_removed')
            ->and($result['message'])->toContain('uses it again');
    });

    it('keeps the old key when another server for the host still uses it', function () {
        $this->serverB->forceFill(['ip' => '192.0.2.10', 'port' => 2222])->saveQuietly();

        $this->removal->handle();

        expect(rotationRemovedKeyOn($this->commands, '192.0.2.10', $this->oldKey))->toBeFalse()
            ->and(Cache::get(RotatePrivateKeyJob::cacheKey($this->oldKey))['servers'][$this->serverA->uuid]['status'])->toBe('moved_old_not_removed');
    });

    it('does not touch a server that moved to another instance', function () {
        $this->serverA->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

        $this->removal->handle();

        expect(collect($this->commands)->filter(fn ($command) => str_contains($command, 'authorized_keys')))->toBeEmpty();
    });
});

it('keeps a server on the old key when the new key cannot log in', function () {
    $this->rejectNewKeyOn = ['192.0.2.11'];

    (new RotatePrivateKeyJob($this->oldKey->id, $this->newKey->id))->handle();

    $state = Cache::get(RotatePrivateKeyJob::cacheKey($this->oldKey));
    expect($this->serverA->fresh()->private_key_id)->toBe($this->newKey->id)
        ->and($this->serverB->fresh()->private_key_id)->toBe($this->oldKey->id)
        ->and($state['servers'][$this->serverB->uuid]['status'])->toBe('failed')
        ->and(rotationRemovedKeyOn($this->commands, '192.0.2.11', $this->oldKey))->toBeFalse()
        ->and(rotationRemovedKeyOn($this->commands, '192.0.2.11', $this->newKey))->toBeFalse()
        ->and(rotationRemovedKeyOn($this->commands, '192.0.2.11', $this->newKey, 'grep -vxF -f'))->toBeTrue();
});

it('skips a transferred server with its own message', function () {
    $this->serverB->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

    (new RotatePrivateKeyJob($this->oldKey->id, $this->newKey->id))->handle();

    $result = Cache::get(RotatePrivateKeyJob::cacheKey($this->oldKey))['servers'][$this->serverB->uuid];
    expect($result['status'])->toBe('skipped')
        ->and($result['message'])->toBe('Another Coolify instance manages this server now.')
        ->and(collect($this->commands)->contains(fn ($command) => str_contains($command, '192.0.2.11')))->toBeFalse();
});

describe('Rotate component', function () {
    beforeEach(function () {
        Queue::fake([RotatePrivateKeyJob::class]);
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
    });

    it('starts a rotation for an owner', function () {
        Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id])
            ->call('rotate')
            ->assertDispatched('success');

        Queue::assertPushed(RotatePrivateKeyJob::class, fn ($job) => $job->oldPrivateKeyId === $this->oldKey->id);
    });

    it('reuses the new key of the previous run when started again', function () {
        Cache::put(RotatePrivateKeyJob::cacheKey($this->oldKey), [
            'status' => 'finished', 'new_private_key_id' => $this->newKey->id, 'servers' => [],
        ]);
        $keys = PrivateKey::count();

        Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id])->call('rotate');

        expect(PrivateKey::count())->toBe($keys);
        Queue::assertPushed(RotatePrivateKeyJob::class, fn ($job) => $job->newPrivateKeyId === $this->newKey->id);
    });

    it('creates the new key with the chosen type', function (string $type, string $class) {
        Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id])
            ->set('keyType', $type)
            ->call('rotate')
            ->assertDispatched('success');

        $newKey = PrivateKey::find(Cache::get(RotatePrivateKeyJob::cacheKey($this->oldKey))['new_private_key_id']);
        $loaded = PublicKeyLoader::load($newKey->private_key);
        expect($loaded)->toBeInstanceOf($class);
        if ($loaded instanceof RSA) {
            expect($loaded->getLength())->toBe(4096);
        }
    })->with([
        'ed25519' => ['ed25519', phpseclib3\Crypt\EC\PrivateKey::class],
        'rsa' => ['rsa', RSA\PrivateKey::class],
    ]);

    it('refuses to create a new key after the key generation rate limit', function (string $type) {
        for ($i = 0; $i < 10; $i++) {
            PrivateKey::generateNewKeyPair();
        }
        $keys = PrivateKey::count();

        Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id])
            ->set('keyType', $type)
            ->call('rotate')
            ->assertDispatched('error', fn (string $name, array $params) => str_contains($params[0], 'Too many requests'))
            ->assertNotDispatched('success');

        expect(PrivateKey::count())->toBe($keys);
        Queue::assertNotPushed(RotatePrivateKeyJob::class);
    })->with(['ed25519', 'rsa']);

    it('rejects an unknown key type', function () {
        Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id])
            ->set('keyType', 'dsa')
            ->call('rotate')
            ->assertHasErrors('keyType');

        Queue::assertNotPushed(RotatePrivateKeyJob::class);
    });

    it('keeps the last result reachable after every server moved', function () {
        Server::query()->update(['private_key_id' => $this->newKey->id]);
        Cache::put(RotatePrivateKeyJob::cacheKey($this->oldKey), [
            'status' => 'finished', 'new_private_key_id' => $this->newKey->id,
            'servers' => [$this->serverA->uuid => ['name' => 'alpha', 'status' => 'moved_old_not_removed', 'message' => 'The old key was not removed: timeout']],
        ]);

        Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id])
            ->assertSee('Rotation result')
            ->assertSee('The old key was not removed: timeout')
            ->assertDontSee('Rotate again');
    });

    it('forbids a team member', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'member']);
        $this->actingAs($member);

        Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id])->assertForbidden();
    });

    it('does not open a key of another team', function () {
        $otherOwner = User::factory()->create();
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($otherOwner, ['role' => 'owner']);
        $this->actingAs($otherOwner);
        session(['currentTeam' => $otherTeam]);

        expect(fn () => Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id]))
            ->toThrow(ModelNotFoundException::class);
        Queue::assertNotPushed(RotatePrivateKeyJob::class);
    });

    it('checks the role in the key team after the session switched to another team', function () {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => 'admin']);
        $ownTeam = Team::factory()->create();
        $ownTeam->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        $component = Livewire::test(Rotate::class, ['privateKeyId' => $this->oldKey->id]);
        $this->team->members()->updateExistingPivot($user->id, ['role' => 'member']);
        session(['currentTeam' => $ownTeam]);
        Cache::flush();
        $this->actingAs($user->fresh());

        $component->call('rotate')->assertDispatched('error')->assertNotDispatched('success');
        Queue::assertNotPushed(RotatePrivateKeyJob::class);
    });
});
