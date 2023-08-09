<?php

namespace App\Http\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;
use Poliander\Cron\CronExpression;

class CreateScheduledBackup extends Component
{
    public $database;
    public $frequency;
    public bool $enabled = true;
    public bool $keep_locally = true;
    public bool $save_s3 = true;

    protected $rules = [
        'frequency' => 'required|string',
        'keep_locally' => 'required|boolean',
        'save_s3' => 'required|boolean',
    ];
    protected $validationAttributes = [
        'frequency' => 'Backup Frequency',
        'keep_locally' => 'Keep Locally',
        'save_s3' => 'Save to S3',
    ];

    public function submit(): void
    {
        try {
            $this->validate();

            $expression = new CronExpression($this->frequency);
            $isValid = $expression->isValid();

            if (isset(VALID_CRON_STRINGS[$this->frequency])) {
                $isValid = true;
            }
            if (!$isValid) {
                $this->emit('error', 'Invalid Cron / Human expression');
                return;
            }
            ScheduledDatabaseBackup::create([
                'enabled' => true,
                'frequency' => $this->frequency,
                'keep_locally' => $this->keep_locally,
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
            $this->keep_locally = true;
            $this->save_s3 = true;
        }
    }
}
