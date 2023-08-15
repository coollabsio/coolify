<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;

class BackupExecutions extends Component
{
    public $backup;
    public $executions;
    protected $listeners = ['refreshBackupExecutions'];

    public function refreshBackupExecutions(): void
    {
        $this->executions = $this->backup->executions;
    }
}
