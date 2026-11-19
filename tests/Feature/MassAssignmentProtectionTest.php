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
use App\Models\StandaloneSqlite;
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
            StandaloneSqlite::class,
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

    /**
     * Relationship keys and uuid are fillable on purpose for internal create and clone
     * flows (see tests/Unit/ModelFillableRegressionTest.php). API controllers reject
     * them through their allowed-field lists, so only the primary key stays guarded.
     */
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
    });

    test('Team model blocks mass assignment of internal fields', function () {
        $team = new Team;

        expect($team->isFillable('id'))->toBeFalse();
        expect($team->isFillable('use_instance_email_settings'))->toBeFalse('use_instance_email_settings should not be fillable (migrated to EmailNotificationSettings)');
        expect($team->isFillable('resend_api_key'))->toBeFalse('resend_api_key should not be fillable (migrated to EmailNotificationSettings)');
    });

    test('standalone database models block mass assignment of their primary key', function () {
        $models = [
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
            StandaloneSqlite::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;
            $dangerousFields = ['id'];

            foreach ($dangerousFields as $field) {
                expect($model->isFillable($field))
                    ->toBeFalse("Model {$modelClass} should not allow mass assignment of '{$field}'");
            }
        }
    });

    test('Application fill ignores non-fillable fields', function () {
        $application = new Application;
        $application->fill([
            'id' => 999,
            'name' => 'test-app',
            'team_id' => 999,
        ]);

        expect($application->name)->toBe('test-app');
        expect($application->id)->toBeNull();
        expect($application->team_id)->toBeNull();
    });

    test('Service model blocks mass assignment of its primary key', function () {
        $service = new Service;

        expect($service->isFillable('id'))->toBeFalse();
    });
});
