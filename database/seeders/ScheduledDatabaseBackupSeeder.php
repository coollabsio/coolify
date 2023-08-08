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
        ScheduledDatabaseBackup::create([
            'frequency' => '* * * * *',
            'database_id' => 1,
            'database_type' => 'App\Models\StandalonePostgresql',
            'team_id' => 0,
        ]);
        ScheduledDatabaseBackup::create([
            'frequency' => '*/2 * * * *',
            'database_id' => 1,
            'database_type' => 'App\Models\StandalonePostgresql',
            'team_id' => 0,
        ]);
        ScheduledDatabaseBackup::create([
            'frequency' => '*/3 * * * *',
            'database_id' => 1,
            'database_type' => 'App\Models\StandalonePostgresql',
            'team_id' => 0,
        ]);
    }
}
