<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DockerImages extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    #[Computed]
    public function images(): Collection
    {
        try {
            $raw = instant_remote_process(
                ["docker images --format '{{json .}}'"],
                $this->server,
                false
            );

            if (! $raw) {
                return collect();
            }

            $lines = array_filter(explode("\n", trim($raw)));
            $images = collect();

            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if (! $data || ! isset($data['Repository'])) {
                    continue;
                }

                $repoTag = $data['Repository'].':'.$data['Tag'];
                $imageId = $data['ID'];

                // Check which containers use this image
                $usedBy = instant_remote_process(
                    ["docker ps -a --filter ancestor={$repoTag} --format '{{.Names}}' 2>/dev/null || true"],
                    $this->server,
                    false
                );

                $images->push([
                    'id' => $imageId,
                    'repository' => $data['Repository'],
                    'tag' => $data['Tag'],
                    'size' => $data['Size'] ?? '?',
                    'created_at' => $data['CreatedAt'] ?? '?',
                    'repo_tag' => $repoTag,
                    'used_by' => $usedBy ? array_filter(explode("\n", trim($usedBy))) : [],
                    'is_dangling' => $data['Repository'] === '<none>' || $data['Tag'] === '<none>',
                    'is_used' => ! empty($usedBy),
                ]);
            }

            return $images;
        } catch (\Throwable) {
            return collect();
        }
    }

    #[Computed]
    public function danglingCount(): int
    {
        return $this->images->filter(fn ($img) => $img['is_dangling'])->count();
    }

    #[Computed]
    public function totalSize(): string
    {
        $totalBytes = 0;
        foreach ($this->images as $img) {
            $size = $img['size'];
            if (preg_match('/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB)$/i', $size, $m)) {
                $value = (float) $m[1];
                $unit = strtoupper($m[2]);
                $multipliers = ['B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3];
                $totalBytes += $value * ($multipliers[$unit] ?? 1);
            }
        }

        if ($totalBytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($totalBytes, 1024));

        return @round($totalBytes / pow(1024, $i), 1).' '.$units[$i];
    }

    public function deleteImage(string $repoTag): void
    {
        try {
            $this->authorize('update', $this->server);
            instant_remote_process(
                ["docker rmi {$repoTag} 2>/dev/null || docker image rm {$repoTag} 2>/dev/null || true"],
                $this->server,
                false
            );
            $this->dispatch('success', "Image {$repoTag} deleted.");
            unset($this->images);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function pruneDangling(): void
    {
        try {
            $this->authorize('update', $this->server);
            instant_remote_process(
                ['docker image prune -af'],
                $this->server,
                false
            );
            $this->dispatch('success', 'All dangling images pruned.');
            unset($this->images);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function pruneAll(): void
    {
        try {
            $this->authorize('update', $this->server);
            instant_remote_process(
                ['docker image prune -af && docker builder prune -af'],
                $this->server,
                false
            );
            $this->dispatch('success', 'All unused images and build cache pruned.');
            unset($this->images);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.docker-images');
    }
}
