<?php

namespace Rocketeers\Laravel\Logging;

use Monolog\LogRecord;
use Rocketeers\Redactor;

/**
 * Scrubs credentials out of every log record, not only the ones reported to Rocketeers.
 * Laravel merges the ambient Context into "extra", so a credential put there once reaches
 * the log file, Slack and the error report alike.
 */
class RedactSensitiveData
{
    public function __construct(private readonly Redactor $redactor) {}

    /**
     * @param  LogRecord|array<string, mixed>  $record
     * @return LogRecord|array<string, mixed>
     */
    public function __invoke($record)
    {
        if ($record instanceof LogRecord) {
            return $record->with(
                message: $this->redactor->redactString($record->message),
                context: $this->redactor->redactArray($record->context),
                extra: $this->redactor->redactArray($record->extra),
            );
        }

        $record['message'] = $this->redactor->redactString($record['message'] ?? '');
        $record['context'] = $this->redactor->redactArray($record['context'] ?? []);
        $record['extra'] = $this->redactor->redactArray($record['extra'] ?? []);

        return $record;
    }
}
