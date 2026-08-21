<?php

use App\Services\MigrationFailure;

function migrationFailureTempPath(): string
{
    return sys_get_temp_dir().'/coolify-migration-failure-'.uniqid().'.json';
}

afterEach(function () {
    if (isset($this->markerPath) && is_file($this->markerPath)) {
        @unlink($this->markerPath);
    }
});

it('returns null when no marker exists', function () {
    $this->markerPath = migrationFailureTempPath();

    expect(MigrationFailure::current($this->markerPath))->toBeNull();
});

it('records and reads back a migration failure', function () {
    $this->markerPath = migrationFailureTempPath();

    MigrationFailure::record('SQLSTATE[HY000]: disk full', new DateTime('2026-08-21T10:00:00+00:00'), $this->markerPath);

    $current = MigrationFailure::current($this->markerPath);

    expect($current)->toMatchArray([
        'message' => 'SQLSTATE[HY000]: disk full',
        'failed_at' => '2026-08-21T10:00:00+00:00',
    ]);
});

it('clears a recorded failure', function () {
    $this->markerPath = migrationFailureTempPath();

    MigrationFailure::record('boom', null, $this->markerPath);
    expect(MigrationFailure::current($this->markerPath))->not->toBeNull();

    MigrationFailure::clear($this->markerPath);
    expect(MigrationFailure::current($this->markerPath))->toBeNull();
});

it('treats an empty or malformed marker as no failure', function (string $contents) {
    $this->markerPath = migrationFailureTempPath();
    file_put_contents($this->markerPath, $contents);

    expect(MigrationFailure::current($this->markerPath))->toBeNull();
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'not json' => 'nonsense',
    'missing message' => '{"failed_at":"2026-08-21T10:00:00+00:00"}',
]);
