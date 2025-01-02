<?php

namespace App\Livewire\Images\Images;

use App\Actions\Docker\DeleteAllDanglingServerDockerImages;
use App\Actions\Docker\DeleteServerDockerImages;
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
    public bool $showDeleteConfirmation = false;
    public array $imagesToDelete = [];
    public string $confirmationText = '';

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

            $images = collect(ListServerDockerImages::run($server));

            // Format sizes for the list
            $this->serverImages = $images->map(function ($image) {
                $image['FormattedSize'] = $this->formatBytes($image['Size'] ?? 0);
                return $image;
            });
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
            $details = GetServerDockerImageDetails::run($server, $imageId);

            // Add formatted size (total size)
            if (isset($details['Size'])) {
                $details['FormattedSize'] = $this->formatBytes($details['Size']);
            } else {
                $details['FormattedSize'] = 'N/A';
            }

            // Add formatted virtual size
            if (isset($details['VirtualSize'])) {
                $details['FormattedVirtualSize'] = $this->formatBytes($details['VirtualSize']);
            }

            // Add formatted creation date
            if (isset($details['Created'])) {
                $details['FormattedCreated'] = \Carbon\Carbon::parse($details['Created'])->diffForHumans();
            } else {
                $details['FormattedCreated'] = 'N/A';
            }

            $this->imageDetails = $details;
        } catch (\Exception $e) {
            $this->addError('details', "Error loading image details: " . $e->getMessage());
        }
    }

    public function confirmDelete($imageId = null)
    {
        if ($imageId) {
            $this->imagesToDelete = [$imageId];
        } else {
            $this->imagesToDelete = $this->selectedImages;
        }

        if (empty($this->imagesToDelete)) {
            return;
        }

        $this->showDeleteConfirmation = true;
    }

    public function deleteImages()
    {
        try {
            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }

            if (empty($this->imagesToDelete)) {
                $this->addError('delete', 'No images selected for deletion');
                return;
            }

            if ($this->confirmationText !== 'delete') {
                $this->addError('confirmation', 'Please type "delete" to confirm');
                return;
            }

            DeleteServerDockerImages::run($server, $this->imagesToDelete);

            // Reset states
            $this->showDeleteConfirmation = false;
            $this->imagesToDelete = [];
            $this->selectedImages = [];
            $this->imageDetails = null;
            $this->confirmationText = '';
            $this->selectAll = false;

            $this->loadServerImages();
            $this->dispatch('success', 'Images deleted successfully.');
        } catch (\Exception $e) {
            $this->addError('delete', "Error deleting images: " . $e->getMessage());
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
                    // Check if RepoTags is an array and has elements
                    $tags = is_array($image['RepoTags']) ? $image['RepoTags'] : [$image['RepoTags']];
                    $tags = array_filter($tags); // Remove empty values

                    // Search in all tags and ID
                    return collect($tags)->some(function ($tag) {
                        return str_contains(strtolower($tag), strtolower($this->searchQuery));
                    }) || str_contains(strtolower($image['Id'] ?? ''), strtolower($this->searchQuery));
                });
            })
            ->when($this->showOnlyDangling, function ($collection) {
                return $collection->filter(function ($image) {
                    return $image['Dangling'] ?? false;
                });
            })
            ->values();
    }

    public function updatedSelectAll($value)
    {
        if ($value) {
            $this->selectedImages = $this->filteredImages->pluck('Id')->toArray();
        } else {
            $this->selectedImages = [];
        }
    }

    public function updatedSearchQuery()
    {
        $this->selectAll = false;
        $this->selectedImages = [];
    }

    public function updatedShowOnlyDangling()
    {
        $this->selectAll = false;
        $this->selectedImages = [];
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
