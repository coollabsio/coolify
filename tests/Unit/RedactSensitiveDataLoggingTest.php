<?php

use App\Logging\RedactSensitiveAuditData;
use App\Logging\RedactSensitiveData;
use App\Services\Security\SensitiveDataRedactor;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

it('redacts messages, nested context, and exceptions before a handler receives the record', function () {
    $secret = 'coolify-canary-secret-a91f0c';
    $handler = new TestHandler;
    $logger = new Logger(new MonologLogger('test', [$handler]));

    (new RedactSensitiveData(new SensitiveDataRedactor))($logger);

    $logger->error(
        "Deployment failed with password={$secret}",
        [
            'headers' => ['Authorization' => "Bearer {$secret}"],
            'exception' => new RuntimeException("token={$secret}"),
        ],
    );

    $record = $handler->getRecords()[0];
    $serializedRecord = serialize([$record->message, $record->context, $record->extra]);

    expect($serializedRecord)
        ->not->toContain($secret)
        ->toContain(REDACTED)
        ->and($record->context['exception']['trace'])->toBeString()->not->toBeEmpty();
});

it('keeps redacted traces and previous exceptions', function () {
    $secret = 'coolify-canary-secret-previous';
    $handler = new TestHandler;
    $logger = new Logger(new MonologLogger('test', [$handler]));

    (new RedactSensitiveData(new SensitiveDataRedactor))($logger);

    $logger->error('Failed', [
        'exception' => new RuntimeException('outer', 0, new RuntimeException("password={$secret}")),
    ]);

    $exception = $handler->getRecords()[0]->context['exception'];

    expect($exception['trace'])->toContain('#0')
        ->and($exception['previous']['message'])->toBe('password='.REDACTED)
        ->and(serialize($exception))->not->toContain($secret);
});

it('wires redaction into all persistent and remote log channels', function () {
    $configuration = file_get_contents(__DIR__.'/../../config/logging.php');

    expect($configuration)
        ->toContain("'channels' => ['single']")
        ->toContain("env('LOG_LEVEL', 'debug')")
        ->not->toContain("env('LOG_LEVEL', 'info')")
        ->and(substr_count($configuration, 'RedactSensitiveData::class'))->toBe(9)
        ->and($configuration)->toMatch("/'audit' => \\[[^\\]]*'tap' => \\[RedactSensitiveAuditData::class\\]/s");
});

it('keeps email addresses in the audit log while still redacting secrets', function () {
    $secret = 'coolify-canary-secret-audit';
    $handler = new TestHandler;
    $logger = new Logger(new MonologLogger('audit', [$handler]));

    (new RedactSensitiveAuditData(new SensitiveDataRedactor))($logger);

    $logger->info("User changed password={$secret}", ['user_email' => 'admin@example.com', 'token' => $secret]);

    $record = $handler->getRecords()[0];

    expect($record->context['user_email'])->toBe('admin@example.com')
        ->and($record->context['token'])->toBe(REDACTED)
        ->and($record->message)->toBe('User changed password='.REDACTED);
});

it('redacts email addresses in the default channels', function () {
    $redactor = new SensitiveDataRedactor;

    expect($redactor->redactText('mail admin@example.com'))->toBe('mail '.REDACTED)
        ->and($redactor->withoutEmailRedaction()->redactText('mail admin@example.com'))->toBe('mail admin@example.com')
        ->and($redactor->redactText('mail admin@example.com'))->toBe('mail '.REDACTED);
});
