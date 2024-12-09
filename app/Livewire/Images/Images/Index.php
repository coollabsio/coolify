<?php

namespace App\Livewire\Images\Images;

use App\Actions\Docker\DeleteAllDanglingServerDockerImages;
use App\Actions\Docker\GetServerDockerImageDetails;
use App\Actions\Docker\ListServerDockerImages;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public string $selected_uuid = 'default';
    public SupportCollection $serverImages;
    public Collection $servers;
    public bool $isLoadingImages = false;
    public array $selectedImages = [];
    public ?array $imageDetails = null;
    public string $searchQuery = '';
    public bool $showOnlyDangling = false;
    public bool $selectAll = false;

    public function mount()
    {
        $this->servers = Server::isReachable()->get();
        $this->serverImages = collect([]);
    }

    public function updatedSelectedUuid()
    {
        $this->loadServerImages();
        $this->selectedImages = [];
    }

    public function loadServerImages()
    {
        $this->isLoadingImages = true;
        $this->imageDetails = null;

        try {
            if ($this->selected_uuid === 'default') {
                return;
            }

            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }

            $this->serverImages = collect(ListServerDockerImages::run($server));
        } catch (\Exception $e) {
            $this->addError('images', "Error loading docker images: " . $e->getMessage());
        } finally {
            $this->isLoadingImages = false;
        }
    }

    public function getImageDetails($imageId)
    {
        try {
            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }
            $this->imageDetails = GetServerDockerImageDetails::run($server, $imageId);

            // Add formatted size
            if (isset($this->imageDetails[0]['Size'])) {
                $size = $this->imageDetails[0]['Size'];
                $this->imageDetails[0]['FormattedSize'] = $this->formatBytes($size);
            }

            // Add formatted creation date
            if (isset($this->imageDetails[0]['Created'])) {
                $this->imageDetails[0]['FormattedCreated'] = \Carbon\Carbon::parse($this->imageDetails[0]['Created'])->diffForHumans();
            }
        } catch (\Exception $e) {
            $this->addError('details', "Error loading image details: " . $e->getMessage());
        }
    }

    public function deleteImage($imageId)
    {
        try {
            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }

            instant_remote_process(["docker rmi -f {$imageId}"], $server);
            $this->imageDetails = null;
            $this->loadServerImages();
        } catch (\Exception $e) {
            $this->addError('delete', "Error deleting image: " . $e->getMessage());
        }
    }

    public function pruneUnused()
    {
        try {
            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }

            DeleteAllDanglingServerDockerImages::run($server);
            $this->loadServerImages();
        } catch (\Exception $e) {
            $this->addError('prune', "Error pruning images: " . $e->getMessage());
        }
    }

    public function getFilteredImagesProperty()
    {
        return $this->serverImages
            ->when($this->searchQuery, function ($collection) {
                return $collection->filter(function ($image) {
                    return str_contains(strtolower($image['Repository'] ?? ''), strtolower($this->searchQuery)) ||
                        str_contains(strtolower($image['Tag'] ?? ''), strtolower($this->searchQuery)) ||
                        str_contains(strtolower($image['ID'] ?? ''), strtolower($this->searchQuery));
                });
            })
            ->when($this->showOnlyDangling, function ($collection) {
                return $collection->filter(function ($image) {
                    return ($image['Repository'] ?? '') === '<none>' || ($image['Tag'] ?? '') === '<none>';
                });
            })
            ->values();
    }

    public function updatedSelectAll($value)
    {
        if ($value) {
            $this->selectedImages = $this->filteredImages->pluck('ID')->toArray();
        } else {
            $this->selectedImages = [];
        }
    }

    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function render()
    {
        return view('livewire.images.images.index', [
            'filteredImages' => $this->getFilteredImagesProperty()
        ]);
    }
}
