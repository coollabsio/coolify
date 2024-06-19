<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Models\Team;
use App\Notifications\Database\DailyBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DatabaseBackupStatusJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct() {}

    public function handle()
    {
        // $teams = Team::all();
        // foreach ($teams as $team) {
        //     $scheduled_backups = $team->scheduledDatabaseBackups()->get();
        //     if ($scheduled_backups->isEmpty()) {
        //         continue;
        //     }
        //     foreach ($scheduled_backups as $scheduled_backup) {
        //         $last_days_backups = $scheduled_backup->get_last_days_backup_status();
        //         if ($last_days_backups->isEmpty()) {
        //             continue;
        //         }
        //         $failed = $last_days_backups->where('status', 'failed');
        //     }
        // }

        // $scheduled_backups = ScheduledDatabaseBackup::all();
        // $databases = collect();
        // $teams = collect();
        // foreach ($scheduled_backups as $scheduled_backup) {
        //     $last_days_backups = $scheduled_backup->get_last_days_backup_status();
        //     if ($last_days_backups->isEmpty()) {
        //         continue;
        //     }
        //     $failed = $last_days_backups->where('status', 'failed');
        //     $database = $scheduled_backup->database;
        //     $team = $database->team();
        //     $teams->put($team->id, $team);
        //     $databases->put("{$team->id}:{$database->name}", [
        //         'failed_count' => $failed->count(),
        //     ]);
        // }
        // foreach ($databases as $name => $database) {
        //     [$team_id, $name] = explode(':', $name);
        //     $team = $teams->get($team_id);
        //     $team?->notify(new DailyBackup($databases));
        // }
    }
}
