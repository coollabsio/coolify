<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait HandlesTestDatabase
{
    protected function setUpTestDatabase(): void
    {
        try {
            // Create test database if it doesn't exist
            $database = config('database.connections.testing.database');
            $this->createTestDatabase($database);

            // Run migrations
            Artisan::call('migrate:fresh', [
                '--database' => 'testing',
                '--seed' => false,
            ]);
        } catch (\Exception $e) {
            $this->tearDownTestDatabase();
            throw $e;
        }
    }

    protected function tearDownTestDatabase(): void
    {
        try {
            // Drop test database
            $database = config('database.connections.testing.database');
            $this->dropTestDatabase($database);
        } catch (\Exception $e) {
            // Log error but don't throw
            error_log('Failed to tear down test database: '.$e->getMessage());
        }
    }

    protected function createTestDatabase($database)
    {
        try {
            // Connect to postgres database to create/drop test database
            config(['database.connections.pgsql.database' => 'postgres']);
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            // Drop if exists and create new database
            DB::connection('pgsql')->statement("DROP DATABASE IF EXISTS $database WITH (FORCE);");
            DB::connection('pgsql')->statement("CREATE DATABASE $database;");

            // Switch back to testing connection
            DB::disconnect('pgsql');
            DB::reconnect('testing');
        } catch (\Exception $e) {
            $this->tearDownTestDatabase();
            throw new \Exception('Could not create test database: '.$e->getMessage());
        }
    }

    protected function dropTestDatabase($database)
    {
        try {
            // Connect to postgres database to drop test database
            config(['database.connections.pgsql.database' => 'postgres']);
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            // Drop the test database
            DB::connection('pgsql')->statement("DROP DATABASE IF EXISTS $database WITH (FORCE);");

            DB::disconnect('pgsql');
        } catch (\Exception $e) {
            // Log error but don't throw
            error_log('Failed to drop test database: '.$e->getMessage());
        }
    }
}
