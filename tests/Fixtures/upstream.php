<?php

/**
 * Router for `php -S` that stands in for the Rocketeers ingest endpoint in AgentIntegrationTest. Each
 * request is appended to requests.jsonl in ROCKETEERS_UPSTREAM_DIR with its headers, its gunzipped body
 * and the status it got; statuses are taken one per request from the "plan" file there, then 202.
 */
$directory = getenv('ROCKETEERS_UPSTREAM_DIR');
$status = 202;

$plan = fopen($directory.'/plan', 'c+');
flock($plan, LOCK_EX);
$statuses = array_values(array_filter(explode("\n", (string) stream_get_contents($plan))));

if ($statuses !== []) {
    $status = (int) array_shift($statuses);

    ftruncate($plan, 0);
    rewind($plan);
    fwrite($plan, implode("\n", $statuses));
}

flock($plan, LOCK_UN);
fclose($plan);

$raw = (string) file_get_contents('php://input');
$headers = getallheaders();

file_put_contents($directory.'/requests.jsonl', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'status' => $status,
    'headers' => $headers,
    'body' => ($headers['Content-Encoding'] ?? '') === 'gzip' ? gzdecode($raw) : $raw,
])."\n", FILE_APPEND | LOCK_EX);

http_response_code($status);
header('Content-Type: application/json');

echo $status === 202
    ? json_encode(['accepted' => ['errors' => 1, 'outgoing_requests' => 0], 'dropped' => 0, 'duplicate' => false])
    : json_encode(['message' => 'Try again later.']);
