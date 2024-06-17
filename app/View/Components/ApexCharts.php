<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ApexCharts extends Component
{
    public string $chartId;
    public $seriesData;
    public $categories;
    public $seriesName;
    public function __construct($chartId, $seriesData, $categories, $seriesName = '')
    {
        $this->chartId = $chartId;
        $this->seriesData = $seriesData;
        $this->categories = $categories;
        $this->seriesName = $seriesName ?? 'Series';
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.apex-charts');
    }
}
