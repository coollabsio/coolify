<?php

use App\Exceptions\EnvironmentAlreadyExistsException;
use App\Exceptions\ProjectNotFoundException;
use App\Models\InstanceSettings;
use App\Support\CreationErrorMessage;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

test('expected creation errors keep their message', function (Throwable $e) {
    expect(CreationErrorMessage::for($e, 'Could not create.'))->toBe($e->getMessage());
})->with([
    'placement' => fn () => new ProjectNotFoundException('abc'),
    'creation' => fn () => new EnvironmentAlreadyExistsException,
    'validation' => fn () => new RuntimeException('The docker_compose_raw should be base64 encoded.'),
    'argument' => fn () => new InvalidArgumentException('Provide either a template slug or docker_compose_raw, not both.'),
]);

test('unexpected errors are reported and replaced with the fallback', function (Throwable $e) {
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e;

        return false;
    });

    expect(CreationErrorMessage::for($e, 'Could not create.'))->toBe('Could not create.')
        ->and($reported)->toHaveCount(1);
})->with([
    'database' => fn () => new QueryException('pgsql', 'insert into "secret_table"', [], new Exception('SQLSTATE[23505]')),
    'generic' => fn () => new Exception('/var/www/html/storage/app/private.key missing'),
]);
