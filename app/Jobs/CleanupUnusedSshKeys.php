<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CleanupUnusedSshKeys
{
    public function handle(): void
    {
        // Read metadata only: cleanup must never decrypt key material.
        $keys = PrivateKey::query()->select(['id', 'uuid'])->get()->keyBy('uuid');
        $rotationStates = $keys->isEmpty() ? [] : Cache::many($keys->map(fn (PrivateKey $key) => RotatePrivateKeyJob::cacheKey($key))->values()->all());
        $rotatingIds = [];
        foreach ($keys as $key) {
            $state = $rotationStates[RotatePrivateKeyJob::cacheKey($key)] ?? null;
            if (is_array($state) && in_array($state['status'] ?? null, ['queued', 'running'], true)) {
                $rotatingIds[] = $key->id;
                $rotatingIds[] = $state['new_private_key_id'] ?? null;
            }
        }
        $keyIdsInUse = null;

        $disk = Storage::disk('ssh-keys');
        foreach ($disk->files() as $filename) {
            if (! preg_match('/\Assh_key@([a-zA-Z0-9_-]+)\z/', $filename, $matches)) {
                continue;
            }

            // Share the writer's local lock; leave the lock file in place.
            $lock = fopen($disk->path($filename).'.lock', 'c');
            if ($lock === false) {
                continue;
            }
            try {
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                clearstatcache(true, $disk->path($filename));
                if (! $disk->exists($filename) || $disk->lastModified($filename) >= now()->subDay()->timestamp) {
                    continue;
                }
                $key = $keys->get($matches[1]);
                $keyIdsInUse ??= $this->keyIdsInUse();
                if ($key && (in_array($key->id, $rotatingIds) || isset($keyIdsInUse[$key->id]))) {
                    continue;
                }
                $disk->delete($filename);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * The ids of the keys that PrivateKey::isInUse() reports as used, as array keys.
     *
     * @return array<int, true>
     */
    private function keyIdsInUse(): array
    {
        return collect([
            Server::query(),
            Application::query()->withoutGlobalScope('withRelations'),
            GithubApp::query(),
            GitlabApp::query(),
            GiteaApp::query(),
        ])
            ->flatMap(fn (Builder $query) => $query->whereNotNull('private_key_id')->distinct()->pluck('private_key_id'))
            ->mapWithKeys(fn (int|string $id) => [(int) $id => true])
            ->all();
    }
}
