<?php

namespace App\Logging;

use App\Services\Security\SensitiveDataRedactor;
use Monolog\Logger;
use Monolog\LogRecord;
use Throwable;

final class RedactSensitiveData
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $this->redact($record));
    }

    private function redact(LogRecord $record): LogRecord
    {
        try {
            $message = $this->redactor->redactText($record->message);
            $recordContext = $this->redactor->redactValue($this->normalizeThrowables($record->context));
            $extra = $this->redactor->redactValue($this->normalizeThrowables($record->extra));

            return $record->with(
                message: $message,
                context: is_array($recordContext) ? $recordContext : [],
                extra: is_array($extra) ? $extra : [],
            );
        } catch (Throwable) {
            return $record->with(
                message: SensitiveDataRedactor::FAILURE_MESSAGE,
                context: [],
                extra: [],
            );
        }
    }

    private function normalizeThrowables(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
                'trace' => $value->getTraceAsString(),
                'previous' => $this->normalizeThrowables($value->getPrevious()),
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map($this->normalizeThrowables(...), $value);
    }
}
