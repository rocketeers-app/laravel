<?php

namespace Rocketeers\Laravel\Ingest;

/**
 * Where app processes find the agent. By default that is a unix socket next to the framework cache:
 * every server of an environment uses the same path without a port to allocate, and the socket's file
 * permissions decide who may write to it. ROCKETEERS_INGEST_URI points elsewhere, for a separate
 * container (tcp) or a storage path too long for a socket address.
 */
class AgentEndpoint
{
    public const SOCKET = 'rocketeers.sock';

    /** Socket addresses top out at 104 bytes on macOS and 108 on Linux; stay clear of both. */
    public const MAX_PATH_BYTES = 100;

    /** @var string */
    protected $uri;

    /**
     * @param  string  $uri  `unix:///abs/path.sock`, `tcp://host:port`, or a bare `host:port` for tcp.
     */
    public function __construct(string $uri)
    {
        $this->uri = static::normalize(trim($uri));
    }

    /**
     * @param  mixed  $configured  rocketeers.ingest.uri
     */
    public static function fromConfig($configured, string $frameworkPath): self
    {
        if (is_string($configured) && trim($configured) !== '') {
            return new static($configured);
        }

        return new static('unix://'.(realpath($frameworkPath) ?: $frameworkPath).'/'.self::SOCKET);
    }

    protected static function normalize(string $uri): string
    {
        if (strpos($uri, '://') !== false) {
            return $uri;
        }

        return strpos($uri, '/') === 0 ? 'unix://'.$uri : 'tcp://'.$uri;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function isUnix(): bool
    {
        return strncmp($this->uri, 'unix://', 7) === 0;
    }

    public function path(): ?string
    {
        return $this->isUnix() ? substr($this->uri, 7) : null;
    }

    public function tooLong(): bool
    {
        return $this->isUnix() && strlen((string) $this->path()) > self::MAX_PATH_BYTES;
    }

    /**
     * A unix socket is there when its file is; a tcp agent can only be found out by connecting.
     */
    public function present(): bool
    {
        if (! $this->isUnix()) {
            return true;
        }

        return ! $this->tooLong() && file_exists((string) $this->path());
    }

    public function listenAddress(): string
    {
        return $this->uri;
    }
}
