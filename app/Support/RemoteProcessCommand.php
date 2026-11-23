<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Spatie\Activitylog\Models\Activity;

/**
 * The command of a remote_process() activity.
 *
 * The queue worker reads the command from the activity, so it must be stored until the
 * task is finished. Commands can contain secrets (database passwords, S3 keys, tunnel
 * tokens), so the command is stored encrypted and removed after the final attempt.
 */
class RemoteProcessCommand
{
    public const PROPERTY = 'command';

    public const ENCRYPTED_PROPERTY = 'command_encrypted';

    /**
     * @return array{command: string, command_encrypted: true}
     */
    public static function properties(string $command): array
    {
        return [
            self::PROPERTY => Crypt::encryptString($command),
            self::ENCRYPTED_PROPERTY => true,
        ];
    }

    /**
     * The plain command, or null when it was already removed.
     * Activities queued before encryption was added store the command as plain text.
     */
    public static function read(Activity $activity): ?string
    {
        $command = $activity->getExtraProperty(self::PROPERTY);
        if (! is_string($command)) {
            return null;
        }

        return $activity->getExtraProperty(self::ENCRYPTED_PROPERTY) === true
            ? Crypt::decryptString($command)
            : $command;
    }

    /**
     * Remove the command after the final attempt of the task.
     * Reads the stored row first, so a status that Coolify set meanwhile is kept.
     */
    public static function forget(Activity $activity): void
    {
        $stored = Activity::query()->whereKey($activity->getKey())->first();
        if ($stored === null) {
            return;
        }

        $stored->properties = $stored->properties->except([self::PROPERTY, self::ENCRYPTED_PROPERTY]);
        $stored->save();

        $activity->properties = $stored->properties;
    }
}
