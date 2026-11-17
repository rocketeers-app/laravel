<?php

namespace Rocketeers\Laravel\Agent;

/**
 * Posts uploads with curl_multi, so a slow API never holds up the agent's loop. One multi handle lives as
 * long as the agent, which keeps the connection to the API open between batches. Name resolution only
 * stays out of the way when curl was built with an asynchronous resolver; the agent warns when it was not.
 */
class CurlTransport implements Transport
{
    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 15;

    public const MAX_RESPONSE_BYTES = 2048;

    /** @var \CurlMultiHandle|resource */
    protected $multi;

    /** @var array<int, array{handle: \CurlHandle|resource, id: string, retry_after: string|null}> */
    protected $transfers = [];

    public function __construct()
    {
        $this->multi = curl_multi_init();
    }

    public static function resolvesAsynchronously(): bool
    {
        $version = curl_version();

        return defined('CURL_VERSION_ASYNCHDNS')
            && is_array($version)
            && ($version['features'] & CURL_VERSION_ASYNCHDNS) === CURL_VERSION_ASYNCHDNS;
    }

    public function post(string $id, string $url, array $headers, string $body): void
    {
        $handle = curl_init($url);
        $key = spl_object_id($handle);

        $this->transfers[$key] = ['handle' => $handle, 'id' => $id, 'retry_after' => null];

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use ($key) {
                if (stripos($line, 'Retry-After:') === 0 && isset($this->transfers[$key])) {
                    $this->transfers[$key]['retry_after'] = trim(substr($line, 12));
                }

                return strlen($line);
            },
        ]);

        curl_multi_add_handle($this->multi, $handle);
    }

    public function poll(): array
    {
        if ($this->transfers === []) {
            return [];
        }

        do {
            $code = curl_multi_exec($this->multi, $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);

        $finished = [];

        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $key = spl_object_id($info['handle']);

            if ($info['msg'] !== CURLMSG_DONE || ! isset($this->transfers[$key])) {
                continue;
            }

            $transfer = $this->transfers[$key];
            $completed = $info['result'] === CURLE_OK;

            $finished[] = [
                'id' => $transfer['id'],
                'status' => $completed ? (int) curl_getinfo($info['handle'], CURLINFO_RESPONSE_CODE) : 0,
                'retry_after' => $transfer['retry_after'],
                'error' => $completed ? null : (curl_error($info['handle']) ?: curl_strerror($info['result'])),
                'body' => $completed ? substr((string) curl_multi_getcontent($info['handle']), 0, self::MAX_RESPONSE_BYTES) : '',
            ];

            curl_multi_remove_handle($this->multi, $info['handle']);
            unset($this->transfers[$key]);
        }

        return $finished;
    }
}
