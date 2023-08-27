<?php

namespace Database\Seeders;

use App\Models\ScheduledDatabaseBackup;
use Illuminate\Database\Seeder;

class ScheduledDatabaseBackupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // ScheduledDatabaseBackup::create([
        //     'enabled' => true,
        //     'frequency' => '* * * * *',
        //     'number_of_backups_locally' => 2,
        //     'database_id' => 1,
        //     'database_type' => 'App\Models\StandalonePostgresql',
        //     's3_storage_id' => 1,
        //     'team_id' => 0,
        // ]);
        // ScheduledDatabaseBackup::create([
        //     'enabled' => true,
        //     'frequency' => '* * * * *',
        //     'number_of_backups_locally' => 3,
        //     'database_id' => 1,
        //     'database_type' => 'App\Models\StandalonePostgresql',
        //     'team_id' => 0,
        // ]);
    }
}
