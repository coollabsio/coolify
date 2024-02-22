<?php

namespace App\View\Components\Status;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Index extends Component
{
    /**
     * Create a new component instance.
     */
    public $status = "exited:unhealthy";
    public function __construct(
        public $resource = null,
        public bool $showRefreshButton = true,
    ) {
        $this->status = $resource->status;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.status.index');
    }
}
