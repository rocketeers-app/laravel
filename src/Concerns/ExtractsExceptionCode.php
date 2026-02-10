<?php

namespace Rocketeers\Laravel\Concerns;

trait ExtractsExceptionCode
{
    protected function getCodeFromException($exception): ?int
    {
        if (! is_object($exception)) {
            return null;
        }

        if (method_exists($exception, 'getStatusCode')) {
            return (int) $exception->getStatusCode();
        }

        if (method_exists($exception, 'getCode')) {
            return (int) $exception->getCode();
        }

        return null;
    }
}
