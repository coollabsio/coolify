<?php

use App\Logging\RedactSensitiveData;
use App\Services\Security\SensitiveDataRedactor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

it('redacts messages, nested context, and exceptions before a handler receives the record', function () {
    $secret = 'coolify-canary-secret-a91f0c';
    $handler = new TestHandler;
    $logger = new Logger('test', [$handler]);

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
    $logger = new Logger('test', [$handler]);

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
        ->toContain("env('LOG_LEVEL', 'info')")
        ->and(substr_count($configuration, 'RedactSensitiveData::class'))->toBe(10);
});
