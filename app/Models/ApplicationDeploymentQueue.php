<?php

namespace App\Models;

use App\Domain\Deployment\DeploymentOutput;
use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Shared\Destination;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $application_id
 * @property string $deployment_uuid
 * @property int $pull_request_id
 * @property bool $force_rebuild
 * @property string $commit
 * @property string $status
 * @property bool $is_webhook
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $logs
 * @property string|null $current_process_id
 * @property bool $restart_only
 * @property string|null $git_type
 * @property int|null $server_id
 * @property string|null $application_name
 * @property string|null $server_name
 * @property string|null $deployment_url
 * @property string|null $destination_id
 * @property bool $only_this_server
 * @property bool $rollback
 * @property string|null $commit_message
 * @property-read \App\Models\Application|null $application
 *
 * @method static \Database\Factories\ApplicationDeploymentQueueFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue query()
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereApplicationName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereCommit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereCommitMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereCurrentProcessId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereDeploymentUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereDeploymentUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereForceRebuild($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereGitType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereIsWebhook($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereLogs($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereOnlyThisServer($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue wherePullRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereRestartOnly($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereRollback($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereServerName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ApplicationDeploymentQueue whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ApplicationDeploymentQueue extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function setStatus(string $status)
    {
        $this->update([
            'status' => $status,
        ]);
    }

    public function setFailed()
    {
        $this->setEnumStatus(ApplicationDeploymentStatus::FAILED);
    }

    public function setInProgress()
    {
        $this->setEnumStatus(ApplicationDeploymentStatus::IN_PROGRESS);
    }

    public function setEnumStatus(ApplicationDeploymentStatus $status)
    {
        $this->update([
            'status' => $status->value,
        ]);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function destination()
    {
        return $this->belongsTo(Destination::class);
    }

    public function getOutput($name)
    {
        if (! $this->logs) {
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

    public function addDeploymentLog(DeploymentOutput $output)
    {
        $previousLogs = [];

        if ($this->logs) {
            $previousLogs = json_decode($this->logs, associative: true, flags: JSON_THROW_ON_ERROR);
            $output->setOrder(count($previousLogs) + 1);
        }

        $previousLogs[] = $output->toArray();

        // TODO: Eventually, 'logs' should be casted to array.
        $this->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
        $this->save();

    }

    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }
        $message = str($message)->trim();
        if ($message->startsWith('╔')) {
            $message = "\n".$message;
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
