<?php

namespace Tests\Feature;

use App\Jobs\DatabaseBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PgBackRestTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgbackrest_migration_adds_required_columns()
    {
        $this->assertTrue(Schema::hasColumn('scheduled_database_backups', 'use_pgbackrest'));
        $this->assertTrue(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_config'));
        $this->assertTrue(Schema::hasColumn('scheduled_database_backups', 'backup_type'));
        $this->assertTrue(Schema::hasColumn('scheduled_database_backups', 'full_backup_frequency'));
    }

    public function test_backup_execution_migration_adds_required_columns()
    {
        $this->assertTrue(Schema::hasColumn('scheduled_database_backup_executions', 'backup_type'));
        $this->assertTrue(Schema::hasColumn('scheduled_database_backup_executions', 'pgbackrest_info'));
    }

    public function test_scheduled_backup_model_casts_pgbackrest_fields()
    {
        $backup = new ScheduledDatabaseBackup();
        
        $this->assertArrayHasKey('use_pgbackrest', $backup->getCasts());
        $this->assertArrayHasKey('full_backup_frequency', $backup->getCasts());
        $this->assertEquals('boolean', $backup->getCasts()['use_pgbackrest']);
        $this->assertEquals('integer', $backup->getCasts()['full_backup_frequency']);
    }

    public function test_backup_job_determines_correct_backup_type()
    {
        // Create a team and database for testing
        $team = Team::factory()->create();
        $database = StandalonePostgresql::factory()->create();
        
        // Create backup schedule with pgBackRest enabled
        $backup = ScheduledDatabaseBackup::create([
            'uuid' => \Str::uuid(),
            'frequency' => 'daily',
            'team_id' => $team->id,
            'database_id' => $database->id,
            'database_type' => StandalonePostgresql::class,
            'use_pgbackrest' => true,
            'backup_type' => 'incr',
            'full_backup_frequency' => 7,
        ]);

        // Test that first backup is full type
        $job = new DatabaseBackupJob($backup);
        $backupType = $this->callPrivateMethod($job, 'determinePgbackrestBackupType');
        
        $this->assertEquals('full', $backupType);
    }

    public function test_backup_job_uses_incremental_after_full()
    {
        $team = Team::factory()->create();
        $database = StandalonePostgresql::factory()->create();
        
        $backup = ScheduledDatabaseBackup::create([
            'uuid' => \Str::uuid(),
            'frequency' => 'daily',
            'team_id' => $team->id,
            'database_id' => $database->id,
            'database_type' => StandalonePostgresql::class,
            'use_pgbackrest' => true,
            'backup_type' => 'incr',
            'full_backup_frequency' => 7,
        ]);

        // Create a recent full backup execution
        ScheduledDatabaseBackupExecution::create([
            'uuid' => \Str::uuid(),
            'scheduled_database_backup_id' => $backup->id,
            'database_name' => 'test',
            'filename' => '/tmp/test.backup',
            'backup_type' => 'full',
            'status' => 'success',
            'local_storage_deleted' => false,
            'created_at' => now()->subDays(2), // 2 days ago
        ]);

        $job = new DatabaseBackupJob($backup);
        $backupType = $this->callPrivateMethod($job, 'determinePgbackrestBackupType');
        
        $this->assertEquals('incr', $backupType);
    }

    public function test_backup_job_uses_full_after_frequency_period()
    {
        $team = Team::factory()->create();
        $database = StandalonePostgresql::factory()->create();
        
        $backup = ScheduledDatabaseBackup::create([
            'uuid' => \Str::uuid(),
            'frequency' => 'daily',
            'team_id' => $team->id,
            'database_id' => $database->id,
            'database_type' => StandalonePostgresql::class,
            'use_pgbackrest' => true,
            'backup_type' => 'incr',
            'full_backup_frequency' => 7,
        ]);

        // Create an old full backup execution
        ScheduledDatabaseBackupExecution::create([
            'uuid' => \Str::uuid(),
            'scheduled_database_backup_id' => $backup->id,
            'database_name' => 'test',
            'filename' => '/tmp/test.backup',
            'backup_type' => 'full',
            'status' => 'success',
            'local_storage_deleted' => false,
            'created_at' => now()->subDays(8), // 8 days ago (>7 day frequency)
        ]);

        $job = new DatabaseBackupJob($backup);
        $backupType = $this->callPrivateMethod($job, 'determinePgbackrestBackupType');
        
        $this->assertEquals('full', $backupType);
    }

    public function test_pgbackrest_config_generation()
    {
        $team = Team::factory()->create();
        $database = StandalonePostgresql::factory()->create([
            'postgres_user' => 'testuser'
        ]);
        
        $backup = ScheduledDatabaseBackup::create([
            'uuid' => \Str::uuid(),
            'frequency' => 'daily',
            'team_id' => $team->id,
            'database_id' => $database->id,
            'database_type' => StandalonePostgresql::class,
            'use_pgbackrest' => true,
            'pgbackrest_config' => "# Custom config\ncompress-level=9",
        ]);

        $job = new DatabaseBackupJob($backup);
        $job->database = $database;
        
        $config = $this->callPrivateMethod($job, 'generate_pgbackrest_config', ['testdb']);
        
        $this->assertStringContainsString('[global]', $config);
        $this->assertStringContainsString('[main]', $config);
        $this->assertStringContainsString('pg1-host-user=testuser', $config);
        $this->assertStringContainsString('compress-level=9', $config);
    }

    /**
     * Helper method to call private methods in tests
     */
    private function callPrivateMethod($object, $methodName, $args = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        
        return $method->invokeArgs($object, $args);
    }
}