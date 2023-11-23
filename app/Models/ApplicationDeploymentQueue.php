<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ApplicationDeploymentQueue extends Model
{
    protected $guarded = [];

    public function getOutput($name) {
        if (!$this->logs) {
            return null;
        }
        return collect(json_decode($this->logs))->where('name', $name)->first()?->output ?? null;
    }

    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }
        $newLogEntry = [
            'command' => null,
            'output' => $message,
            'type' => $type,
            'timestamp' => Carbon::now('UTC'),
            'hidden' => $hidden,
            'batch' => 1,
        ];
        if ($this->logs) {
            $previousLogs = json_decode($this->logs, associative: true, flags: JSON_THROW_ON_ERROR);
            $newLogEntry['order'] = count($previousLogs) + 1;
            $previousLogs[] = $newLogEntry;
            $this->update([
                'logs' => json_encode($previousLogs, flags: JSON_THROW_ON_ERROR),
            ]);
        } else {
            $this->update([
                'logs' => json_encode([$newLogEntry], flags: JSON_THROW_ON_ERROR),
            ]);
        }
    }
}
