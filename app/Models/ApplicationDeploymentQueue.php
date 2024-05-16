<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ApplicationDeploymentQueue extends Model
{
    protected $guarded = [];

    public function setStatus(string $status)
    {
        $this->update([
            'status' => $status,
        ]);
    }
    public function getOutput($name)
    {
        if (!$this->logs) {
            return null;
        }
        return collect(json_decode($this->logs))->where('name', $name)->first()?->output ?? null;
    }
    public function commitMessage()
    {
        if (empty($this->commit_message) || is_null($this->commit_message)) {
            return null;
        }
        return str($this->commit_message)->trim()->limit(50)->value();
    }
    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }
        $message = str($message)->trim();
        if ($message->startsWith('╔')) {
            $message = "\n" . $message;
        }
        $newLogEntry = [
            'command' => null,
            'output' => remove_iip($message),
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
