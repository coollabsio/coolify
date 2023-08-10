<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;

class BackupEdit extends Component
{
    public $backup;
    public array $parameters;

    protected $rules = [
        'backup.enabled' => 'required|boolean',
        'backup.frequency' => 'required|string',
        'backup.number_of_backups_locally' => 'required|integer|min:1',
        'backup.save_s3' => 'required|boolean',
    ];
    protected $validationAttributes = [
        'backup.enabled' => 'Enabled',
        'backup.frequency' => 'Frequency',
        'backup.number_of_backups_locally' => 'Number of Backups Locally',
        'backup.save_s3' => 'Save to S3',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }


    public function delete()
    {
        // TODO: Delete backup from server and add a confirmation modal
        $this->backup->delete();
        redirect()->route('project.database.backups.all', $this->parameters);
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
