<?php

namespace Rocketeers\Laravel\Ingest;

use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle middleware on Laravel's HTTP client that turns each outgoing request into an `outgoing_request`
 * record: method, host, masked path, status, timing and byte counts. It never reads a body and never
 * keeps a header, query string, fragment or userinfo, so nothing a request carried can end up in the
 * record. Failed requests are recorded too, and the failure is handed back to the caller untouched.
 */
class RecordOutgoingRequests
{
    /** libcurl errors raised while negotiating or verifying TLS. */
    public const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /** Guzzle 8 tells timeouts apart by class, and only says "cURL error 28" in the message. */
    protected const TIMEOUT_EXCEPTIONS = [
        'GuzzleHttp\Exception\ConnectTimeoutException',
        'GuzzleHttp\Exception\NetworkTimeoutException',
        'GuzzleHttp\Exception\ResponseTimeoutException',
    ];

    /** @var AgentConnection */
    protected $connection;

    /** @var RecordBuffer */
    protected $buffer;

    /** @var ExecutionContext */
    protected $context;

    public function __construct(AgentConnection $connection, RecordBuffer $buffer, ExecutionContext $context)
    {
        $this->connection = $connection;
        $this->buffer = $buffer;
        $this->context = $context;
    }

    /**
     * A handler may also throw instead of rejecting (Laravel's fakes do), which is recorded the same way.
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if (! $this->context->sampled() || ! $this->connection->present()) {
                return $handler($request, $options);
            }

            $startedAt = microtime(true);
            $start = hrtime(true);

            try {
                $promise = $handler($request, $options);
            } catch (GuzzleException $e) {
                $this->record($request, $startedAt, $start, static::responseOf($e), $e, true);

                throw $e;
            }

            return $promise->then(
                function ($response) use ($request, $startedAt, $start) {
                    $this->record($request, $startedAt, $start, $response instanceof ResponseInterface ? $response : null);

                    return $response;
                },
                function ($reason) use ($request, $startedAt, $start) {
                    $this->record($request, $startedAt, $start, static::responseOf($reason), $reason, true);

                    return Create::rejectionFor($reason);
                }
            );
        };
    }

    /**
     * @param  int|float  $start  hrtime(true) when the request was handed on.
     * @param  mixed  $reason
     */
    protected function record(RequestInterface $request, float $startedAt, $start, ?ResponseInterface $response, $reason = null, bool $failed = false): void
    {
        $duration = round(max(0, hrtime(true) - $start) / 1e6, 2);

        try {
            $uri = $request->getUri();
            $scheme = strtolower($uri->getScheme());

            $this->buffer->push((string) json_encode([
                't' => 'outgoing_request',
                'v' => 1,
                'trace_id' => $this->context->traceId(),
                'timestamp' => static::timestamp($startedAt),
                'method' => strtoupper($request->getMethod()),
                'scheme' => $scheme,
                'host' => strtolower($uri->getHost()),
                'port' => static::port($scheme, $uri->getPort()),
                'path' => PathMask::mask($uri->getPath() === '' ? '/' : $uri->getPath()),
                'status' => $response !== null ? $response->getStatusCode() : null,
                'duration_ms' => $duration,
                'request_size' => $request->getBody()->getSize(),
                'response_size' => $response !== null ? static::responseSize($response) : null,
                'error' => $failed ? static::error($reason) : null,
                'execution_type' => $this->context->type(),
                'execution_name' => $this->context->name(),
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        } catch (Throwable $e) {
            return;
        }
    }

    /**
     * @param  mixed  $reason  Whatever the request was rejected with.
     */
    public static function error($reason): ?string
    {
        $errno = static::errno($reason);

        if ($errno !== null) {
            if ($errno === 28) {
                return 'timeout';
            }

            if ($errno === 6) {
                return 'dns';
            }

            if ($errno === 7) {
                return 'connect';
            }

            return in_array($errno, self::TLS_ERRNOS, true) ? 'tls' : 'other';
        }

        if (is_a($reason, 'GuzzleHttp\Exception\BadResponseException')) {
            return null;
        }

        foreach (self::TIMEOUT_EXCEPTIONS as $class) {
            if (is_a($reason, $class)) {
                return 'timeout';
            }
        }

        return is_a($reason, 'GuzzleHttp\Exception\ConnectException') ? 'connect' : 'other';
    }

    /**
     * Guzzle 6 and 7 hand over the curl errno in the handler context; Guzzle 8 only puts it in the
     * message, which every Guzzle version formats as "cURL error {errno}: ...".
     *
     * @param  mixed  $reason
     */
    protected static function errno($reason): ?int
    {
        if (is_object($reason) && method_exists($reason, 'getHandlerContext')) {
            $errno = $reason->getHandlerContext()['errno'] ?? null;

            if (is_int($errno) && $errno > 0) {
                return $errno;
            }
        }

        if ($reason instanceof Throwable && preg_match('/^cURL error (\d+):/', $reason->getMessage(), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  mixed  $reason
     */
    protected static function responseOf($reason): ?ResponseInterface
    {
        $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;

        return $response instanceof ResponseInterface ? $response : null;
    }

    protected static function responseSize(ResponseInterface $response): ?int
    {
        $size = $response->getBody()->getSize();

        if ($size !== null) {
            return $size;
        }

        $length = $response->getHeaderLine('Content-Length');

        return ctype_digit($length) ? (int) $length : null;
    }

    protected static function port(string $scheme, ?int $port): ?int
    {
        if ($port === null || ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return null;
        }

        return $port;
    }

    protected static function timestamp(float $time): string
    {
        $moment = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $time));

        return $moment === false ? gmdate('Y-m-d\TH:i:s.000\Z', (int) $time) : $moment->format('Y-m-d\TH:i:s.v\Z');
    }
}
