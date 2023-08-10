<?php

namespace App\Http\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;

class CreateScheduledBackup extends Component
{
    public $database;
    public $frequency;
    public bool $enabled = true;
    public bool $save_s3 = true;

    protected $rules = [
        'frequency' => 'required|string',
        'save_s3' => 'required|boolean',
    ];
    protected $validationAttributes = [
        'frequency' => 'Backup Frequency',
        'save_s3' => 'Save to S3',
    ];

    public function submit(): void
    {
        try {
            $this->validate();
            $isValid = validate_cron_expression($this->frequency);
            if (!$isValid) {
                $this->emit('error', 'Invalid Cron / Human expression');
                return;
            }
            ScheduledDatabaseBackup::create([
                'enabled' => true,
                'frequency' => $this->frequency,
                'save_s3' => $this->save_s3,
                'database_id' => $this->database->id,
                'database_type' => $this->database->getMorphClass(),
                'team_id' => auth()->user()->currentTeam()->id,
            ]);
            $this->emit('refreshScheduledBackups');
        } catch (\Exception $e) {
            general_error_handler($e, $this);
        } finally {
            $this->frequency = '';
            $this->save_s3 = true;
        }
    }
}
