<?php

namespace Rocketeers\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Rocketeers\Laravel\Agent\Agent;
use Rocketeers\Laravel\Agent\CurlTransport;
use Rocketeers\Laravel\Agent\Log;
use Rocketeers\Laravel\Agent\Uploader;
use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\Ingest\IngestKey;
use Rocketeers\Rocketeers;
use RuntimeException;

/**
 * Runs the local agent under a process supervisor. Without a token, with ingest disabled or in an
 * environment that does not report, it idles instead of exiting, so the supervisor never gives up on it;
 * a second copy waits for the first to stop instead of fighting it for the socket.
 */
class RunAgentCommand extends Command
{
    public const LOCK_FILE = 'rocketeers-agent.lock';

    public const LOCK_RETRY_SECONDS = 5;

    public const IDLE_SECONDS = 60;

    protected $signature = 'rocketeers:agent
        {--listen= : Address to listen on instead of ROCKETEERS_INGEST_URI or the default socket}
        {--drain=5 : Seconds to keep uploading after a stop signal}
        {--max-memory=128 : Stop gracefully above this many MB, for the supervisor to restart}';

    protected $description = 'Run the Rocketeers agent, which uploads errors and outgoing requests in the background.';

    /** @var bool */
    protected $stopRequested = false;

    /** @var Agent|null */
    protected $agent;

    public function handle(): int
    {
        AgentAwareRocketeers::bypass(true);

        $this->listenForSignals();

        if (($reason = $this->idleReason()) !== null) {
            return $this->idle($reason);
        }

        $endpoint = filled($this->option('listen'))
            ? new AgentEndpoint((string) $this->option('listen'))
            : $this->laravel->make(AgentEndpoint::class);

        if ($endpoint->tooLong()) {
            $this->error(sprintf(
                'The socket path %s is %d bytes long, more than the %d a unix socket allows. Set ROCKETEERS_INGEST_URI to a shorter unix:// path, or to tcp://127.0.0.1:<port>.',
                $endpoint->path(),
                strlen((string) $endpoint->path()),
                AgentEndpoint::MAX_PATH_BYTES
            ));

            return self::FAILURE;
        }

        if (($lock = $this->lock()) === null) {
            return $this->stopRequested ? self::SUCCESS : self::FAILURE;
        }

        try {
            return $this->runAgent($endpoint);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function runAgent(AgentEndpoint $endpoint): int
    {
        $token = (string) config('rocketeers.api_token');
        $version = Agent::packageVersion();
        $log = new Log(function (string $level, string $message) {
            $this->writeLog($level, $message);
        });

        $this->agent = new Agent($endpoint->listenAddress(), IngestKey::fromToken($token), new Uploader(
            new CurlTransport,
            rtrim((string) (config('rocketeers.api_url') ?: Rocketeers::baseUrl()), '/'),
            $token,
            $version,
            null,
            null,
            $log
        ), $log, [
            'version' => $version,
            'drain' => (float) $this->option('drain'),
            'stats' => $this->output->isVerbose(),
        ]);

        try {
            $this->agent->listen();
        } catch (RuntimeException $e) {
            $this->writeLog('error', $e->getMessage());

            return self::FAILURE;
        }

        $this->writeLog('info', sprintf('Rocketeers agent %s listening on %s', $version, $this->agent->address()));

        if (! CurlTransport::resolvesAsynchronously()) {
            $this->writeLog('warning', 'This curl has no asynchronous DNS resolver, so a slow DNS lookup stalls the agent while it lasts.');
        }

        if ($this->stopRequested) {
            $this->agent->stop();
        }

        $maxMemory = max(1, (int) $this->option('max-memory')) * 1048576;

        $this->agent->run(function (Agent $agent) use ($maxMemory) {
            if (! $agent->stopping() && memory_get_usage(true) > $maxMemory) {
                $this->writeLog('warning', sprintf('Memory use passed %d MB; stopping so the supervisor starts a fresh agent.', $maxMemory / 1048576));

                $agent->stop();
            }
        });

        return self::SUCCESS;
    }

    protected function idleReason(): ?string
    {
        if (empty(config('rocketeers.api_token'))) {
            return 'ROCKETEERS_API_TOKEN is not set';
        }

        if (! config('rocketeers.ingest.enabled', true)) {
            return 'ingest is disabled (ROCKETEERS_INGEST=false)';
        }

        if (! in_array($this->laravel->environment(), (array) config('rocketeers.environments', []))) {
            return sprintf('the "%s" environment is not listed in rocketeers.environments', $this->laravel->environment());
        }

        return null;
    }

    protected function idle(string $reason): int
    {
        $this->writeLog('info', sprintf('Rocketeers agent idle: %s. Errors are sent directly and no outgoing requests are recorded.', $reason));

        while (! $this->stopRequested) {
            $this->sleepFor(self::IDLE_SECONDS);
        }

        return self::SUCCESS;
    }

    /**
     * Only the holder of the lock may replace a socket left behind, so a second agent waits its turn.
     *
     * @return resource|null
     */
    protected function lock()
    {
        $path = storage_path('framework/'.self::LOCK_FILE);
        $lock = @fopen($path, 'c');

        if ($lock === false) {
            $this->writeLog('error', sprintf('Could not open the lock file %s.', $path));

            return null;
        }

        $waiting = false;

        while (! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($this->stopRequested) {
                fclose($lock);

                return null;
            }

            if (! $waiting) {
                $waiting = true;

                $this->writeLog('info', sprintf('Another Rocketeers agent holds %s; waiting for it to stop.', $path));
            }

            $this->sleepFor(self::LOCK_RETRY_SECONDS);
        }

        return $lock;
    }

    protected function listenForSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->writeLog('warning', 'ext-pcntl is not loaded, so a stop signal ends the agent at once: records it holds are lost and its socket stays behind until the next start.');

            return;
        }

        $stop = function () {
            $this->stopRequested = true;

            if ($this->agent !== null) {
                $this->agent->stop();
            }
        };

        if (method_exists($this, 'trap')) {
            $this->trap([SIGTERM, SIGINT, SIGQUIT], $stop);

            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, $stop);
        }
    }

    protected function sleepFor(int $seconds): void
    {
        sleep($seconds);
    }

    protected function writeLog(string $level, string $message): void
    {
        $line = '['.date('Y-m-d H:i:s').'] '.$message;

        if ($level === 'error') {
            $this->error($line);
        } elseif ($level === 'warning') {
            $this->warn($line);
        } else {
            $this->line($line);
        }
    }
}
