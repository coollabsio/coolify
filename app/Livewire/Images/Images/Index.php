<?php

namespace App\Livewire\Images\Images;

use App\Actions\Docker\DeleteAllDanglingServerDockerImages;
use App\Actions\Docker\DeleteServerDockerImages;
use App\Actions\Docker\GetServerDockerImageDetails;
use App\Actions\Docker\UpdateServerDockerImageTag;
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

    public $editingImageId = null;
    public $newTag = '';
    public $newRepo = '';
    public function mount()
    {
        $this->servers = Server::isReachable()->get();
        $this->serverImages = collect([]);
    }

    /**
     * Whenever user picks a new server, load images & reset selection
     */
    public function updatedSelectedUuid()
    {
        $this->loadServerImages();
        $this->resetSelection();
    }

    /**
     * "Select all" checkbox toggled
     */
    public function updatedSelectAll($value)
    {
        // If selectAll is true, grab all filtered images' IDs
        // If false, clear out selectedImages
        $this->selectedImages = $value
            ? $this->filteredImages->pluck('Id')->toArray()
            : [];
    }

    /**
     * Clears out selections (helper function)
     */
    protected function resetSelection()
    {
        $this->selectedImages = [];
        $this->selectAll = false;
    }
    /**
     * Whenever we search or change "dangling only", reset selection
     */
    public function updatedSearchQuery()
    {
        $this->resetSelection();
    }

    public function updatedShowOnlyDangling()
    {
        $this->resetSelection();
    }

    /**
     * Load images for the chosen server
     */
    public function loadServerImages()
    {
        $this->isLoadingImages = true;
        $this->imageDetails = null;

        if ($this->selected_uuid === 'default') {
            return;
        }

        try {
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
            $this->dispatch('error', "Error loading docker images: " . $e->getMessage());
        } finally {
            $this->isLoadingImages = false;
        }
    }

    /**
     * Fetch details for a specific image
     */
    public function getImageDetails($imageId)
    {
        try {
            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }

            $details = GetServerDockerImageDetails::run($server, $imageId);

            // Add some nicely formatted fields
            $details['FormattedSize'] = isset($details['Size'])
                ? $this->formatBytes($details['Size'])
                : 'N/A';

            if (isset($details['VirtualSize'])) {
                $details['FormattedVirtualSize'] = $this->formatBytes($details['VirtualSize']);
            }

            if (isset($details['Created'])) {
                $details['FormattedCreated'] = \Carbon\Carbon::parse($details['Created'])->diffForHumans();
            } else {
                $details['FormattedCreated'] = 'N/A';
            }

            $this->imageDetails = $details;
        } catch (\Exception $e) {
            $this->dispatch('error', "Error loading image details: " . $e->getMessage());
        }
    }

    public function confirmDelete($imageId = null)
    {
        // You can open a modal here or similar
        // dd($imageId);
        $this->showDeleteConfirmation = true;
    }

    public function deleteImages($imageId = null)
    {
        // If a single ID was passed, we delete just that
        // Otherwise, we delete the entire selection
        $this->imagesToDelete = $imageId
            ? [$imageId]
            : $this->selectedImages;

        if (empty($this->imagesToDelete)) {
            $this->dispatch('error', 'No images selected for deletion');
            return;
        }

        try {
            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }

            DeleteServerDockerImages::run($server, $this->imagesToDelete);

            // Reset states
            $this->showDeleteConfirmation = false;
            $this->imagesToDelete = [];
            $this->resetSelection();
            $this->imageDetails = null;
            $this->confirmationText = '';

            // Reload images
            $this->loadServerImages();

            $this->dispatch('success', 'Images deleted successfully.');
        } catch (\Exception $e) {
            $this->dispatch('error', "Error deleting images: " . $e->getMessage());
        }
    }

    /**
     * Delete all dangling images
     */
    public function deleteUnusedImages()
    {
        try {
            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }

            $unusedIds = $this->filteredImages
                ->filter(fn($image) => ($image['Status'] ?? '') === 'unused')
                ->pluck('Id')
                ->toArray();

            DeleteServerDockerImages::run($server, $unusedIds);

            $this->loadServerImages();
            $this->dispatch('success', 'Unused images deleted successfully.');
        } catch (\Exception $e) {
            $this->dispatch('error', "Error deleting unused images: " . $e->getMessage());
        }
    }

    /**
     * Prune (delete) *dangling* images
     */
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
            $this->dispatch('error', "Error pruning images: " . $e->getMessage());
        }
    }

    /**
     * Dynamically filter images based on search/dangling
     */
    public function getFilteredImagesProperty()
    {
        return $this->serverImages
            ->when($this->searchQuery, function ($collection) {
                return $collection->filter(function ($image) {
                    $tags = is_array($image['RepoTags'])
                        ? $image['RepoTags']
                        : [$image['RepoTags']];

                    $tags = array_filter($tags); // remove null or empty

                    // search by any tag or the ID
                    $matchTag = collect($tags)->some(function ($tag) {
                        return str_contains(strtolower($tag), strtolower($this->searchQuery));
                    });

                    $matchId = str_contains(strtolower($image['Id'] ?? ''), strtolower($this->searchQuery));

                    return $matchTag || $matchId;
                });
            })
            ->when($this->showOnlyDangling, function ($collection) {
                return $collection->filter(function ($image) {
                    return $image['Dangling'] ?? false;
                });
            })
            ->values();
    }

    /**
     * Return how many images are "unused"
     */
    public function getUnusedImagesCountProperty()
    {
        return $this->serverImages
            ->filter(fn($image) => ($image['Status'] ?? '') === 'unused')
            ->count();
    }

    /**
     * Convert bytes to a human-readable format
     */
    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow)); // same as pow(1024, $pow)

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function render()
    {
        return view('livewire.images.images.index', [
            'filteredImages' => $this->filteredImages,
        ]);
    }

    public function startEditingTag($imageId)
    {
        $this->editingImageId = $imageId;
        $image = $this->serverImages->firstWhere('Id', $imageId);
        if ($image && isset($image['RepoTags'])) {
            $tag = is_array($image['RepoTags']) ? $image['RepoTags'][0] : $image['RepoTags'];
            $this->newTag = explode(':', $tag)[1] ?? '';
            $this->newRepo = explode(':', $tag)[0] ?? '';
        }
    }

    public function updateTag()
    {
        try {
            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                return;
            }

            UpdateServerDockerImageTag::run($server, $this->editingImageId, $this->newRepo, $this->newTag);

            // Reset states
            $this->editingImageId = null;
            $this->newTag = '';
            $this->newRepo = '';


            // Reload images
            $this->loadServerImages();
            $this->dispatch('success', 'Image tag updated successfully.');
        } catch (\Exception $e) {
            $this->dispatch('error', "Error updating tag: " . $e->getMessage());
        }
    }

    public function cancelEditTag()
    {
        $this->editingImageId = null;
        $this->newTag = '';
        $this->newRepo = '';
    }
}
