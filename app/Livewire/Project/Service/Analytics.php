<?php

namespace App\Livewire\Project\Service;

use App\Livewire\Project\Shared\ResourceTrafficAnalytics;
use App\Models\Service;
use Livewire\Attributes\Lazy;

#[Lazy]
class Analytics extends ResourceTrafficAnalytics
{
    public Service $service;

    public string $chartId = 'service-analytics';

    protected function resourceProperty(): string
    {
        return 'service';
    }
}
