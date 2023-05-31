<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Deployments extends Component
{
    public int $application_id;
    public $deployments = [];
    public string $current_url;
    public int $skip = 0;
    public int $default_take = 8;
    public bool $show_next = true;

    public function mount()
    {
        $this->current_url = url()->current();
    }
    public function reload_deployments()
    {
        $this->load_deployments();
    }
    public function load_deployments(int|null $take = null)
    {
        ray('Take' . $take);
        if ($take) {
            ray('Take is not null');
            $this->skip = $this->skip + $take;
        }

        $take = $this->default_take;
        $this->deployments = Application::find($this->application_id)->deployments($this->skip, $take);

        if (count($this->deployments) !== 0 && count($this->deployments) < $take) {
            $this->show_next = false;
            return;
        }
    }
}
