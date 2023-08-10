<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;

class BackupEdit extends Component
{
    public $backup;

    protected $rules = [
        'backup.enabled' => 'required|boolean',
        'backup.frequency' => 'required|string',
        'backup.number_of_backups_locally' => 'required|integer|min:1',
    ];
    protected $validationAttributes = [
        'backup.enabled' => 'Enabled',
        'backup.frequency' => 'Frequency',
        'backup.number_of_backups_locally' => 'Number of Backups Locally',
    ];

    public function delete()
    {
        $this->backup->delete();
        $this->emit('success', 'Backup deleted successfully');
        $this->emit('refreshScheduledBackups');
    }

    public function instantSave()
    {
        $this->backup->save();
        $this->backup->refresh();
        $this->emit('success', 'Backup updated successfully');
    }

    public function submit()
    {
        $isValid = validate_cron_expression($this->backup->frequency);
        if (!$isValid) {
            $this->emit('error', 'Invalid Cron / Human expression');
            return;
        }
        $this->validate();
        $this->backup->save();
        $this->backup->refresh();
        $this->emit('success', 'Backup updated successfully');
    }
}
