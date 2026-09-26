<?php

namespace Rocketeers\Laravel\Logging;

use Illuminate\Log\Logger;

/**
 * Channel tap that puts the redactor on a log channel. The service provider adds it to every
 * configured channel, so an app gets redaction without changing its logging config.
 */
class RedactLogChannel
{
    public function __construct(private readonly RedactSensitiveData $processor) {}

    public function __invoke(Logger $logger): void
    {
        if (! method_exists($logger->getLogger(), 'pushProcessor')) {
            return;
        }

        $logger->pushProcessor($this->processor);
    }
}
