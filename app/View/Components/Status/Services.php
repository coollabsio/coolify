<?php

namespace App\View\Components\Status;

use App\Models\Service;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Services extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public Service $service,
        public string $complexStatus = 'exited',
        public bool $showRefreshButton = true
    ) {
        $this->complexStatus = $service->status;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.status.services');
    }
}
