<?php

use App\Actions\Migration\RunMigration;
use Illuminate\Support\Facades\Http;

test('dry run discovers resources from the source api without exporting', function () {
    Http::fake([
        'source.test/api/v1/migrations/preflight' => Http::response([
            'version' => '4.2.0',
            'token_can_write' => true,
            'token_can_read_sensitive' => true,
        ]),
        'target.test/api/v1/migrations/preflight' => Http::response([
            'version' => '4.2.0',
            'token_can_write' => true,
            'token_can_read_sensitive' => true,
        ]),
        'source.test/api/v1/migrations/resources' => Http::response([
            [
                'uuid' => 'app-1',
                'type' => 'application',
                'name' => 'Demo',
                'warnings' => [],
            ],
        ]),
    ]);

    $this->artisan('coolify:migrate', [
        '--source-url' => 'https://source.test',
        '--source-token' => 'source-token',
        '--target-url' => 'https://target.test',
        '--target-token' => 'target-token',
        '--resources' => 'app-1',
        '--dry-run' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/migrations/preflight'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/migrations/resources'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/migrations/export'));
});

test('fails when the source token cannot read sensitive data', function () {
    Http::fake([
        'source.test/api/v1/migrations/preflight' => Http::response([
            'version' => '4.2.0',
            'token_can_write' => true,
            'token_can_read_sensitive' => false,
        ]),
        'target.test/api/v1/migrations/preflight' => Http::response([
            'version' => '4.2.0',
            'token_can_write' => true,
            'token_can_read_sensitive' => true,
        ]),
    ]);

    $this->artisan('coolify:migrate', [
        '--source-url' => 'https://source.test',
        '--source-token' => 'source-token',
        '--target-url' => 'https://target.test',
        '--target-token' => 'target-token',
        '--dry-run' => true,
        '--no-interaction' => true,
    ])->assertFailed();
});

test('run migration handle can be invoked directly for dry runs', function () {
    Http::fake([
        'source.test/api/v1/migrations/preflight' => Http::response([
            'version' => '4.2.0',
            'token_can_write' => true,
            'token_can_read_sensitive' => true,
        ]),
        'target.test/api/v1/migrations/preflight' => Http::response([
            'version' => '4.2.0',
            'token_can_write' => true,
            'token_can_read_sensitive' => true,
        ]),
        'source.test/api/v1/migrations/resources' => Http::response([]),
    ]);

    $exit = RunMigration::run(
        'https://source.test',
        'source-token',
        'https://target.test',
        'target-token',
        [
            'dry_run' => true,
            'no_interaction' => true,
            'resources' => 'missing',
        ],
    );

    expect($exit)->toBe(1);
});
