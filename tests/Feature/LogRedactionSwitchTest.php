<?php

use App\Logging\RedactSensitiveData;
use App\Services\Security\SensitiveDataRedactor;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

it('writes raw framework logs when a self-hosted instance turns redaction off', function () {
    config(['logging.redaction_enabled' => false, 'constants.coolify.self_hosted' => true]);
    $handler = new TestHandler;
    $logger = new Logger(new MonologLogger('test', [$handler]));

    (new RedactSensitiveData(new SensitiveDataRedactor))($logger);
    $logger->error('password=coolify-canary-raw-secret');

    expect($handler->getRecords()[0]->message)->toBe('password=coolify-canary-raw-secret');
});

it('always redacts framework logs on cloud', function () {
    config(['logging.redaction_enabled' => false, 'constants.coolify.self_hosted' => false]);
    $handler = new TestHandler;
    $logger = new Logger(new MonologLogger('test', [$handler]));

    (new RedactSensitiveData(new SensitiveDataRedactor))($logger);
    $logger->error('password=coolify-canary-cloud-secret');

    expect($handler->getRecords()[0]->message)->toBe('password='.REDACTED);
});
