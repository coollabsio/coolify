<?php

use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;

beforeEach(function () {
    config([
        'logging.redaction_enabled' => true,
        'logging.channels.slack.url' => 'https://hooks.slack.invalid/services/test',
        'logging.channels.papertrail.handler_with.host' => '127.0.0.1',
        'logging.channels.papertrail.handler_with.port' => 514,
    ]);
});

/**
 * Runs a record through the processors of the real channel, as Monolog does before a handler writes it.
 */
function logChannelProcessedRecord(string $channel, string $message, array $context = []): LogRecord
{
    $record = new LogRecord(new DateTimeImmutable, $channel, Level::Error, $message, $context);

    foreach (array_reverse(Log::channel($channel)->getLogger()->getProcessors()) as $processor) {
        $record = $processor($record);
    }

    return $record;
}

it('redacts secrets in every persistent and remote log channel', function (string $channel) {
    $secret = 'coolify-canary-secret-channel';

    $record = logChannelProcessedRecord($channel, "Deploy failed with password={$secret}", ['token' => $secret, 'user_email' => 'admin@example.com']);

    expect($record->message)->toBe('Deploy failed with password='.REDACTED)
        ->and($record->context['token'])->toBe(REDACTED)
        ->and($record->context['user_email'])->toBe(REDACTED);
})->with(['stack', 'single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog', 'scheduled', 'scheduled-errors']);

it('redacts secrets but keeps email addresses in the audit channel', function () {
    $secret = 'coolify-canary-secret-audit-channel';

    $record = logChannelProcessedRecord('audit', "User changed password={$secret}", ['token' => $secret, 'user_email' => 'admin@example.com']);

    expect($record->message)->toBe('User changed password='.REDACTED)
        ->and($record->context['token'])->toBe(REDACTED)
        ->and($record->context['user_email'])->toBe('admin@example.com');
});

it('writes the default stack to the single channel at debug level', function () {
    expect(config('logging.channels.stack.channels'))->toBe(['single'])
        ->and(config('logging.channels.single.level'))->toBe('debug');
});
