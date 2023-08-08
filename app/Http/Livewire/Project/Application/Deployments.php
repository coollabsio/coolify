<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Deployments extends Component
{
    public Application $application;
    public $deployments = [];
    public int $deployments_count = 0;
    public string $current_url;
    public int $skip = 0;
    public int $default_take = 8;
    public bool $show_next = false;

    public function mount()
    {
        $this->current_url = url()->current();
        $this->show_more();
    }

    private function show_more()
    {
        if (count($this->deployments) !== 0) {
            $this->show_next = true;
            if (count($this->deployments) < $this->default_take) {
                $this->show_next = false;
            }
            return;
        }
    }

    public function reload_deployments()
    {
        $this->load_deployments();
    }

    public function load_deployments(int|null $take = null)
    {
        if ($take) {
            $this->skip = $this->skip + $take;
        }
        $take = $this->default_take;

        ['deployments' => $deployments, 'count' => $count] = $this->application->deployments($this->skip, $take);
        $this->deployments = $deployments;
        $this->deployments_count = $count;
        $this->show_more();
    }
}
