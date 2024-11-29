<?php

namespace App\Livewire\Images\Images;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class Index extends Component
{
    public string $selected_uuid = 'default';
    public array $serverImages = [];
    public Collection $servers;
    public bool $isLoadingImages = false;

    public function mount()
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }
        $this->servers = Server::isReachable()->get();
    }

    //Zove se automatski kad se na fronti selecta server
    public function updatedSelectedUuid()
    {
        $this->loadServerImages();
    }

    public function loadServerImages()
    {
        $this->isLoadingImages = true;

        try {
            if ($this->selected_uuid === 'default') {
                dd('Please select a server.');
                return;
            }

            $server = $this->servers->firstWhere('uuid', $this->selected_uuid);
            if (!$server) {
                dd('Server not found');
                return;
            }

            // 1. Koristi instant_remote_process "docker images" 
            // 2. Parse output u $serverImages array
            // 3. repository, tag, id, size, created_at

        } catch (\Exception $e) {
            dd("Error loading docker images: " . $e->getMessage());
        } finally {
            $this->isLoadingImages = false;
        }
    }
    public function getImageDetails($imageId) {}

    public function deleteImage($imageId) {}

    public function pruneUnused() {}


    public function render()
    {
        return view('livewire.images.images.index');
    }
}
