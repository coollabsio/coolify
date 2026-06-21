<?php

use App\Models\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('widens existing HTTP Basic Authentication password columns before storing long encrypted passwords', function () {
    $defaultConnection = config('database.default');

    config()->set('database.connections.basic_auth_migration_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'basic_auth_migration_test');
    DB::purge('basic_auth_migration_test');

    try {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->string('name');
            $table->string('git_repository');
            $table->string('git_branch');
            $table->string('build_pack');
            $table->string('ports_exposes');
            $table->unsignedBigInteger('environment_id');
            $table->boolean('is_http_basic_auth_enabled')->default(false);
            $table->string('http_basic_auth_username')->nullable();
            $table->string('http_basic_auth_password')->nullable();
            $table->timestamps();
        });

        expect(Schema::getColumnType('applications', 'http_basic_auth_password'))->toBe('varchar');

        $migrationFiles = glob(database_path('migrations/*_widen_application_http_basic_auth_password_column.php'));
        expect($migrationFiles)->toHaveCount(1);

        $migration = require $migrationFiles[0];
        $migration->up();

        expect(Schema::getColumnType('applications', 'http_basic_auth_password'))->toBe('text');

        $password = str_repeat('a', 64);

        $application = Application::withoutEvents(fn () => Application::create([
            'uuid' => 'basic-auth-password-storage-test',
            'name' => 'basic-auth-password-storage-test',
            'git_repository' => 'https://github.com/coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => 1,
            'is_http_basic_auth_enabled' => true,
            'http_basic_auth_username' => 'admin',
            'http_basic_auth_password' => $password,
        ]));

        $rawPassword = DB::table('applications')
            ->where('id', $application->id)
            ->value('http_basic_auth_password');

        expect(strlen($rawPassword))->toBeGreaterThan(255)
            ->and(Crypt::decryptString($rawPassword))->toBe($password)
            ->and($application->fresh()->http_basic_auth_password)->toBe($password);
    } finally {
        DB::disconnect('basic_auth_migration_test');
        config()->set('database.default', $defaultConnection);
    }
});
