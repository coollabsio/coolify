<?php

namespace App\View\Components\Services;

use App\Models\Service;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class Links extends Component
{
    public Collection $links;
    public function __construct(public Service $service)
    {
        $this->links = collect([]);
        $service->applications()->get()->map(function ($application) {
            $this->links->push($application->fqdn);
        });
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.services.links');
    }
}
