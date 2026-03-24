<?php

namespace Tests\Feature;

use App\Services\Backup\PgBackrestService;
use App\Models\StandalonePostgresql;
use App\Models\Server;
use Tests\TestCase;

class PgBackrestTest extends TestCase
{
    private function createMockDatabase(): StandalonePostgresql
    {
        $database = new StandalonePostgresql();
        $database->uuid = 'test-db-uuid-123';
        $database->postgres_user = 'postgres';
        $database->postgres_db = 'testdb';

        return $database;
    }

    private function createMockServer(): Server
    {
        $server = new Server();
        $server->id = 1;

        return $server;
    }

    public function test_stanza_name_is_derived_from_uuid(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $this->assertEquals('db-test-db-uuid-123', $service->getStanzaName());
    }

    public function test_generate_config_creates_valid_config(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $config = $service->generateConfig();

        $this->assertStringContainsString('[global]', $config);
        $this->assertStringContainsString('[db-test-db-uuid-123]', $config);
        $this->assertStringContainsString('pg1-path=/var/lib/postgresql/data', $config);
        $this->assertStringContainsString('pg1-user=postgres', $config);
        $this->assertStringContainsString('repo1-type=posix', $config);
        $this->assertStringContainsString('compress-type=zst', $config);
    }

    public function test_generate_config_with_s3_uses_s3_repo(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $s3 = new \App\Models\S3Storage();
        $s3->bucket = 'test-bucket';
        $s3->endpoint = 'https://s3.amazonaws.com';
        $s3->region = 'us-east-1';
        $s3->key = 'AKIATEST';
        $s3->secret = 'secretkey';

        $config = $service->generateConfig($s3);

        $this->assertStringContainsString('repo1-type=s3', $config);
        $this->assertStringContainsString('repo1-s3-bucket=test-bucket', $config);
        $this->assertStringContainsString('repo1-s3-endpoint=https://s3.amazonaws.com', $config);
        $this->assertStringNotContainsString('repo1-type=posix', $config);
    }

    public function test_build_backup_command_validates_type(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $this->assertStringContainsString('--type=full', $service->buildBackupCommand('full'));
        $this->assertStringContainsString('--type=incr', $service->buildBackupCommand('incr'));
        $this->assertStringContainsString('--type=diff', $service->buildBackupCommand('diff'));
        $this->assertStringContainsString('--type=incr', $service->buildBackupCommand('invalid'));
    }

    public function test_build_restore_command_with_pitr(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $cmd = $service->buildRestoreCommand('2026-03-22 12:00:00');

        $this->assertStringContainsString('--delta', $cmd);
        $this->assertStringContainsString('--type=time', $cmd);
        $this->assertStringContainsString('2026-03-22 12:00:00', $cmd);
    }

    public function test_build_restore_command_without_pitr(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $cmd = $service->buildRestoreCommand();

        $this->assertStringContainsString('--delta', $cmd);
        $this->assertStringNotContainsString('--type=time', $cmd);
    }

    public function test_wal_archive_params_contains_required_settings(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $params = $service->getWalArchiveParams();

        $this->assertContains('-c', $params);
        $this->assertContains('wal_level=replica', $params);
        $this->assertContains('archive_mode=on', $params);
        $this->assertTrue(
            collect($params)->contains(fn ($p) => str_contains($p, 'archive_command=pgbackrest'))
        );
    }

    public function test_install_script_contains_error_handling(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $script = $service->generateInstallScript();

        $this->assertStringContainsString('set -e', $script);
        $this->assertStringContainsString('command -v pgbackrest', $script);
        $this->assertStringContainsString('ERROR: pgBackRest installation failed', $script);
    }

    public function test_container_backup_commands_use_postgres_user(): void
    {
        $database = $this->createMockDatabase();
        $server = $this->createMockServer();
        $service = new PgBackrestService($database, $server);

        $commands = $service->buildContainerBackupCommands('full');

        $this->assertCount(1, $commands);
        $this->assertStringContainsString('-u postgres', $commands[0]);
        $this->assertStringContainsString('test-db-uuid-123', $commands[0]);
    }
}
