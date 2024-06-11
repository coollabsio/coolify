<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;
use Spatie\Url\Url;

class BackupEdit extends Component
{
    public ?ScheduledDatabaseBackup $backup;

    public $s3s;

    public ?string $status = null;

    public array $parameters;

    protected $rules = [
        'backup.enabled' => 'required|boolean',
        'backup.frequency' => 'required|string',
        'backup.number_of_backups_locally' => 'required|integer|min:1',
        'backup.save_s3' => 'required|boolean',
        'backup.s3_storage_id' => 'nullable|integer',
        'backup.databases_to_backup' => 'nullable',
    ];

    protected $validationAttributes = [
        'backup.enabled' => 'Enabled',
        'backup.frequency' => 'Frequency',
        'backup.number_of_backups_locally' => 'Number of Backups Locally',
        'backup.save_s3' => 'Save to S3',
        'backup.s3_storage_id' => 'S3 Storage',
        'backup.databases_to_backup' => 'Databases to Backup',
    ];

    protected $messages = [
        'backup.s3_storage_id' => 'Select a S3 Storage',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if (is_null(data_get($this->backup, 's3_storage_id'))) {
            data_set($this->backup, 's3_storage_id', 'default');
        }
    }

    public function delete()
    {
        try {
            $this->backup->delete();
            if ($this->backup->database->getMorphClass() === 'App\Models\ServiceDatabase') {
                $previousUrl = url()->previous();
                $url = Url::fromString($previousUrl);
                $url = $url->withoutQueryParameter('selectedBackupId');
                $url = $url->withFragment('backups');
                $url = $url->getPath()."#{$url->getFragment()}";

                return redirect($url);
            } else {
                return redirect()->route('project.database.backup.index', $this->parameters);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->custom_validate();
            $this->backup->save();
            $this->backup->refresh();
            $this->dispatch('success', 'Backup updated successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function custom_validate()
    {
        if (! is_numeric($this->backup->s3_storage_id)) {
            $this->backup->s3_storage_id = null;
        }
        $isValid = validate_cron_expression($this->backup->frequency);
        if (! $isValid) {
            throw new \Exception('Invalid Cron / Human expression');
        }
        $this->validate();
    }

    public function submit()
    {
        try {
            $this->custom_validate();
            if ($this->backup->databases_to_backup == '' || $this->backup->databases_to_backup === null) {
                $this->backup->databases_to_backup = null;
            }
            $this->backup->save();
            $this->backup->refresh();
            $this->dispatch('success', 'Backup updated successfully');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }
}
