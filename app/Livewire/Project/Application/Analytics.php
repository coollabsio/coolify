<?php

namespace App\Livewire\Project\Application;

use App\Livewire\Project\Shared\ResourceTrafficAnalytics;
use App\Models\Application;
use Livewire\Attributes\Lazy;

#[Lazy]
class Analytics extends ResourceTrafficAnalytics
{
    public Application $application;

    public string $chartId = 'application-analytics';

    protected function resourceProperty(): string
    {
        return 'application';
    }
}
