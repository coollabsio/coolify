<?php

namespace App\Jobs;

use App\Models\PrivateKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CleanupUnusedSshKeys
{
    public function handle(): void
    {
        // Read metadata only: cleanup must never decrypt key material.
        $keys = PrivateKey::query()->select(['id', 'uuid'])->get()->keyBy('uuid');
        $rotatingIds = [];
        foreach ($keys as $key) {
            $state = Cache::get(RotatePrivateKeyJob::cacheKey($key), []);
            if (in_array($state['status'] ?? null, ['queued', 'running'], true)) {
                $rotatingIds[] = $key->id;
                $rotatingIds[] = $state['new_private_key_id'] ?? null;
            }
        }

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
                if ($key && (in_array($key->id, $rotatingIds) || $key->isInUse())) {
                    continue;
                }
                $disk->delete($filename);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
