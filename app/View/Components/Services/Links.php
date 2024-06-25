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
            $type = $application->serviceType();
            if ($type) {
                $links = generateServiceSpecificFqdns($application);
                $links = $links->map(function ($link) {
                    return getFqdnWithoutPort($link);
                });
                $this->links = $this->links->merge($links);
            } else {
                if ($application->fqdn) {
                    $fqdns = collect(str($application->fqdn)->explode(','));
                    $fqdns->map(function ($fqdn) {
                        $this->links->push(getFqdnWithoutPort($fqdn));
                    });
                }
                if ($application->ports) {
                    $portsCollection = collect(str($application->ports)->explode(','));
                    $portsCollection->map(function ($port) {
                        if (str($port)->contains(':')) {
                            $hostPort = str($port)->before(':');
                        } else {
                            $hostPort = $port;
                        }
                        $this->links->push(base_url(withPort: false).":{$hostPort}");
                    });
                }
            }
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
