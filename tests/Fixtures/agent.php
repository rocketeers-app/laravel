<?php

/**
 * Runs the agent core on its own, for AgentIntegrationTest:
 *
 *     php agent.php <listen address> <api url> <token>
 *
 * SIGTERM and SIGINT stop it the way rocketeers:agent's signal handlers do.
 */

use Rocketeers\Laravel\Agent\Agent;
use Rocketeers\Laravel\Agent\CurlTransport;
use Rocketeers\Laravel\Agent\Log;
use Rocketeers\Laravel\Agent\Uploader;
use Rocketeers\Laravel\Ingest\IngestKey;

require __DIR__.'/../../vendor/autoload.php';

[, $address, $apiUrl, $token] = $argv;

$log = new Log(function (string $level, string $message) {
    fwrite(STDERR, '['.$level.'] '.$message."\n");
});

$agent = new Agent($address, IngestKey::fromToken($token), new Uploader(new CurlTransport, $apiUrl, $token, 'integration', 'integration-host', null, $log), $log, [
    'version' => 'integration',
    'drain' => 5,
]);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);

    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, function () use ($agent) {
            $agent->stop();
        });
    }
}

$agent->listen();

fwrite(STDERR, "listening\n");

$agent->run();

exit(0);
