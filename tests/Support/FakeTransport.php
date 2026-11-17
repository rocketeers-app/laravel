<?php

namespace Rocketeers\Laravel\Tests\Support;

use Rocketeers\Laravel\Agent\Transport;

/**
 * Keeps every post and finishes it on the next poll with the next scripted status (202 once the script
 * runs out), so a test decides exactly what the API answers.
 */
class FakeTransport implements Transport
{
    /** @var array<int, array{id: string, url: string, headers: string[], body: string}> */
    public array $posts = [];

    /** @var array<int, int|array{status: int, retry_after?: string, error?: string}> */
    public array $script = [];

    /** @var string[] */
    protected array $open = [];

    public function post(string $id, string $url, array $headers, string $body): void
    {
        $this->posts[] = ['id' => $id, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $this->open[] = $id;
    }

    public function poll(): array
    {
        $finished = [];

        foreach ($this->open as $id) {
            $answer = array_shift($this->script) ?? 202;
            $answer = is_array($answer) ? $answer : ['status' => $answer];

            $finished[] = [
                'id' => $id,
                'status' => $answer['status'],
                'retry_after' => $answer['retry_after'] ?? null,
                'error' => $answer['error'] ?? null,
                'body' => '',
            ];
        }

        $this->open = [];

        return $finished;
    }

    /** @return string[] The records of a post, decompressed. */
    public function lines(int $post): array
    {
        return explode("\n", gzdecode($this->posts[$post]['body']));
    }

    public function header(int $post, string $name): ?string
    {
        foreach ($this->posts[$post]['headers'] as $header) {
            if (stripos($header, $name.':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
