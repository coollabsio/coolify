<?php

use App\Enums\ProcessStatus;
use App\Support\RemoteProcessCommand;
use Illuminate\Database\Migrations\Migration;
use Spatie\Activitylog\Models\Activity;

return new class extends Migration
{
    /**
     * Remove the stored command from remote process activities that no longer run.
     *
     * Before this release the command was stored as plain text and kept for 60 days. It can
     * contain secrets such as database passwords, S3 keys and tunnel tokens. Queued or running
     * tasks keep their command, because the queue worker still reads it; the task removes it
     * itself when it ends. Queued or running rows older than one day are stuck and are cleaned too.
     */
    public function up(): void
    {
        Activity::query()
            ->whereNotNull('properties->'.RemoteProcessCommand::PROPERTY)
            ->where(fn ($query) => $query
                ->whereNotIn('properties->status', [ProcessStatus::QUEUED->value, ProcessStatus::IN_PROGRESS->value])
                ->orWhereNull('properties->status')
                ->orWhere('updated_at', '<', now()->subDay()))
            ->chunkById(500, function ($activities) {
                foreach ($activities as $activity) {
                    $activity->properties = $activity->properties->except([
                        RemoteProcessCommand::PROPERTY,
                        RemoteProcessCommand::ENCRYPTED_PROPERTY,
                    ]);
                    $activity->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // The removed commands cannot be restored.
    }
};
