<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;

class BackupEdit extends Component
{
    public $backup;
    public $s3s;
    public array $parameters;

    protected $rules = [
        'backup.enabled' => 'required|boolean',
        'backup.frequency' => 'required|string',
        'backup.number_of_backups_locally' => 'required|integer|min:1',
        'backup.save_s3' => 'required|boolean',
        'backup.s3_storage_id' => 'nullable|integer',
    ];
    protected $validationAttributes = [
        'backup.enabled' => 'Enabled',
        'backup.frequency' => 'Frequency',
        'backup.number_of_backups_locally' => 'Number of Backups Locally',
        'backup.save_s3' => 'Save to S3',
        'backup.s3_storage_id' => 'S3 Storage',
    ];
    protected $messages = [
        'backup.s3_storage_id' => 'Select a S3 Storage',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if (is_null($this->backup->s3_storage_id)) {
            $this->backup->s3_storage_id = 'default';
        }
    }


    public function delete()
    {
        // TODO: Delete backup from server and add a confirmation modal
        $this->backup->delete();
        redirect()->route('project.database.backups.all', $this->parameters);
    }

    public function instantSave()
    {
        try {
            $this->custom_validate();
            $this->backup->save();
            $this->backup->refresh();
            $this->emit('success', 'Backup updated successfully');
        } catch (\Exception $e) {
            $this->emit('error', $e->getMessage());
        }
    }

    private function custom_validate()
    {
        if (!is_numeric($this->backup->s3_storage_id)) {
            $this->backup->s3_storage_id = null;
        }
        $isValid = validate_cron_expression($this->backup->frequency);
        if (!$isValid) {
            throw new \Exception('Invalid Cron / Human expression');
        }
        $this->validate();
    }

    public function submit()
    {
        ray($this->backup->s3_storage_id);
        try {
            $this->custom_validate();
            $this->backup->save();
            $this->backup->refresh();
            $this->emit('success', 'Backup updated successfully');
        } catch (\Exception $e) {
            $this->emit('error', $e->getMessage());
        }
    }
}
