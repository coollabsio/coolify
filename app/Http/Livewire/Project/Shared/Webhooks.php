<?php

namespace App\Http\Livewire\Project\Shared;

use Livewire\Component;

class Webhooks extends Component
{
    public $resource;
    public ?string $deploywebhook = null;
    public function mount()
    {
        $this->deploywebhook = generateDeployWebhook($this->resource);
    }
    public function render()
    {
        return view('livewire.project.shared.webhooks');
    }
}
