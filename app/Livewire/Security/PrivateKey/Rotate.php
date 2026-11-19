<?php

namespace App\Livewire\Security\PrivateKey;

use App\Jobs\RotatePrivateKeyJob;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

class Rotate extends Component
{
    use AuthorizesRequests;

    public PrivateKey $privateKey;

    /** The dialog stays open when the component renders again. */
    public bool $open = false;

    public bool $wasRunning = false;

    /** The type of the new key. RSA works on hosts that do not accept Ed25519, such as FIPS hosts. */
    public string $keyType = 'ed25519';

    public function mount(int $privateKeyId): void
    {
        $this->privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($privateKeyId);
        $this->authorize('update', $this->privateKey);
    }

    public function rotate(): void
    {
        $this->validate(['keyType' => 'required|in:ed25519,rsa']);
        try {
            $this->authorize('update', $this->privateKey);
            if (! $this->servers()->exists()) {
                $this->dispatch('error', 'No server uses this key.');

                return;
            }

            $state = Cache::get(RotatePrivateKeyJob::cacheKey($this->privateKey), []);
            if (data_get($state, 'status') === 'running' && now()->subHour()->lt(data_get($state, 'started_at'))) {
                $this->dispatch('error', 'A rotation of this key is already running.');

                return;
            }

            // A second run reuses the new key of the previous run, so servers that already moved stay on it.
            $newKey = PrivateKey::where('team_id', $this->privateKey->team_id)->find(data_get($state, 'new_private_key_id'))
                ?? $this->createNewKey();

            Cache::put(RotatePrivateKeyJob::cacheKey($this->privateKey), [
                'status' => 'running',
                'started_at' => now()->toIso8601String(),
                'new_private_key_id' => $newKey->id,
                'servers' => [],
            ], RotatePrivateKeyJob::RESULT_TTL_SECONDS);
            RotatePrivateKeyJob::dispatch($this->privateKey->id, $newKey->id, auth()->id());

            auditLog('ui.private_key.rotation_started', [
                'team_id' => $this->privateKey->team_id,
                'private_key_uuid' => $this->privateKey->uuid,
                'private_key_name' => $this->privateKey->name,
                'new_private_key_uuid' => $newKey->uuid,
            ]);
            $this->wasRunning = true;
            $this->dispatch('success', 'Key rotation started.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function createNewKey(): PrivateKey
    {
        $keyPair = $this->keyType === 'rsa'
            ? PrivateKey::generateNewKeyPair('rsa', 4096)
            : PrivateKey::generateNewKeyPair('ed25519');
        $baseName = preg_replace('/( \(rotated \d{4}-\d{2}-\d{2}\))+$/', '', $this->privateKey->name);

        return PrivateKey::createAndStore([
            'name' => Str::limit($baseName, 200, '').' (rotated '.now()->format('Y-m-d').')',
            'description' => $this->privateKey->description,
            'private_key' => $keyPair['private_key'],
            'team_id' => $this->privateKey->team_id,
        ]);
    }

    private function servers()
    {
        return Server::where('team_id', $this->privateKey->team_id)->where('private_key_id', $this->privateKey->id);
    }

    public function render()
    {
        $state = Cache::get(RotatePrivateKeyJob::cacheKey($this->privateKey));
        $newKey = $state ? PrivateKey::where('team_id', $this->privateKey->team_id)->find(data_get($state, 'new_private_key_id')) : null;
        $servers = $this->servers()->with('settings')->get();

        // One row per server: the servers that still use this key, and the servers of the last rotation.
        $rows = collect(data_get($state, 'servers', []))
            ->map(fn (array $result) => [...$result, 'reachable' => true]);
        foreach ($servers as $server) {
            $rows[$server->uuid] ??= [
                'name' => $server->name,
                'status' => null,
                'reachable' => (bool) data_get($server, 'settings.is_reachable'),
                'transferred' => $server->isTransferredAway(),
            ];
        }

        $isRunning = data_get($state, 'status') === 'running';
        if ($this->wasRunning && ! $isRunning) {
            // The servers moved to the new key, so the "Used by" list of the key changed.
            $this->dispatch('privateKeyRotated')->to(Show::class);
        }
        $this->wasRunning = $isRunning;

        return view('livewire.security.private-key.rotate', [
            'rows' => $rows,
            'hasServers' => $servers->isNotEmpty(),
            'usedByOthers' => $this->privateKey->applications()->exists() || $this->privateKey->githubApps()->exists()
                || $this->privateKey->gitlabApps()->exists() || $this->privateKey->giteaApps()->exists(),
            'state' => $state,
            'newKey' => $newKey,
            'isRunning' => $isRunning,
        ]);
    }
}
