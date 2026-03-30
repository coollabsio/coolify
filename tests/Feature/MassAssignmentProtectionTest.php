<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;

describe('mass assignment protection', function () {

    test('no API-exposed model uses unguarded $guarded = []', function () {
        $models = [
            Application::class,
            Service::class,
            User::class,
            Team::class,
            Server::class,
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;
            $guarded = $model->getGuarded();
            $fillable = $model->getFillable();

            // Model must NOT have $guarded = [] (empty guard = no protection)
            // It should either have non-empty $guarded OR non-empty $fillable
            $hasProtection = $guarded !== ['*'] ? count($guarded) > 0 : true;
            $hasProtection = $hasProtection || count($fillable) > 0;

            expect($hasProtection)
                ->toBeTrue("Model {$modelClass} has no mass assignment protection (empty \$guarded and empty \$fillable)");
        }
    });

    test('Application model blocks mass assignment of relationship IDs', function () {
        $application = new Application;
        $dangerousFields = ['id', 'uuid', 'environment_id', 'destination_id', 'destination_type', 'source_id', 'source_type', 'private_key_id', 'repository_project_id'];

        foreach ($dangerousFields as $field) {
            expect($application->isFillable($field))
                ->toBeFalse("Application model should not allow mass assignment of '{$field}'");
        }
    });

    test('Application model allows mass assignment of user-facing fields', function () {
        $application = new Application;
        $userFields = ['name', 'description', 'git_repository', 'git_branch', 'build_pack', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'health_check_path', 'limits_memory', 'status'];

        foreach ($userFields as $field) {
            expect($application->isFillable($field))
                ->toBeTrue("Application model should allow mass assignment of '{$field}'");
        }
    });

    test('Server model has $fillable and no conflicting $guarded', function () {
        $server = new Server;
        $fillable = $server->getFillable();
        $guarded = $server->getGuarded();

        expect($fillable)->not->toBeEmpty('Server model should have explicit $fillable');

        // Guarded should be the default ['*'] when $fillable is set, not []
        expect($guarded)->not->toBe([], 'Server model should not have $guarded = [] overriding $fillable');
    });

    test('Server model blocks mass assignment of dangerous fields', function () {
        $server = new Server;

        // These fields should not be mass-assignable via the API
        expect($server->isFillable('id'))->toBeFalse();
        expect($server->isFillable('uuid'))->toBeFalse();
        expect($server->isFillable('created_at'))->toBeFalse();
    });

    test('User model blocks mass assignment of auth-sensitive fields', function () {
        $user = new User;

        expect($user->isFillable('id'))->toBeFalse('User id should not be fillable');
        expect($user->isFillable('email_verified_at'))->toBeFalse('email_verified_at should not be fillable');
        expect($user->isFillable('remember_token'))->toBeFalse('remember_token should not be fillable');
        expect($user->isFillable('two_factor_secret'))->toBeFalse('two_factor_secret should not be fillable');
        expect($user->isFillable('two_factor_recovery_codes'))->toBeFalse('two_factor_recovery_codes should not be fillable');
        expect($user->isFillable('pending_email'))->toBeFalse('pending_email should not be fillable');
        expect($user->isFillable('email_change_code'))->toBeFalse('email_change_code should not be fillable');
        expect($user->isFillable('email_change_code_expires_at'))->toBeFalse('email_change_code_expires_at should not be fillable');
    });

    test('User model allows mass assignment of profile fields', function () {
        $user = new User;

        expect($user->isFillable('name'))->toBeTrue();
        expect($user->isFillable('email'))->toBeTrue();
        expect($user->isFillable('password'))->toBeTrue();
    });

    test('Team model blocks mass assignment of internal fields', function () {
        $team = new Team;

        expect($team->isFillable('id'))->toBeFalse();
        expect($team->isFillable('use_instance_email_settings'))->toBeFalse('use_instance_email_settings should not be fillable (migrated to EmailNotificationSettings)');
        expect($team->isFillable('resend_api_key'))->toBeFalse('resend_api_key should not be fillable (migrated to EmailNotificationSettings)');
    });

    test('Team model allows mass assignment of expected fields', function () {
        $team = new Team;

        expect($team->isFillable('name'))->toBeTrue();
        expect($team->isFillable('description'))->toBeTrue();
        expect($team->isFillable('personal_team'))->toBeTrue();
        expect($team->isFillable('show_boarding'))->toBeTrue();
        expect($team->isFillable('custom_server_limit'))->toBeTrue();
    });

    test('standalone database models block mass assignment of relationship IDs', function () {
        $models = [
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;
            $dangerousFields = ['id', 'uuid', 'environment_id', 'destination_id', 'destination_type'];

            foreach ($dangerousFields as $field) {
                expect($model->isFillable($field))
                    ->toBeFalse("Model {$modelClass} should not allow mass assignment of '{$field}'");
            }
        }
    });

    test('standalone database models allow mass assignment of config fields', function () {
        $model = new StandalonePostgresql;
        expect($model->isFillable('name'))->toBeTrue();
        expect($model->isFillable('postgres_user'))->toBeTrue();
        expect($model->isFillable('postgres_password'))->toBeTrue();
        expect($model->isFillable('image'))->toBeTrue();
        expect($model->isFillable('limits_memory'))->toBeTrue();

        $model = new StandaloneRedis;
        expect($model->isFillable('redis_conf'))->toBeTrue();

        $model = new StandaloneMysql;
        expect($model->isFillable('mysql_root_password'))->toBeTrue();

        $model = new StandaloneMongodb;
        expect($model->isFillable('mongo_initdb_root_username'))->toBeTrue();
    });

    test('Application fill ignores non-fillable fields', function () {
        $application = new Application;
        $application->fill([
            'name' => 'test-app',
            'environment_id' => 999,
            'destination_id' => 999,
            'team_id' => 999,
            'private_key_id' => 999,
        ]);

        expect($application->name)->toBe('test-app');
        expect($application->environment_id)->toBeNull();
        expect($application->destination_id)->toBeNull();
        expect($application->private_key_id)->toBeNull();
    });

    test('Service model blocks mass assignment of relationship IDs', function () {
        $service = new Service;

        expect($service->isFillable('id'))->toBeFalse();
        expect($service->isFillable('uuid'))->toBeFalse();
        expect($service->isFillable('environment_id'))->toBeFalse();
        expect($service->isFillable('destination_id'))->toBeFalse();
        expect($service->isFillable('server_id'))->toBeFalse();
    });
});
