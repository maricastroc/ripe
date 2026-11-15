<?php

declare(strict_types=1);

parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $scenario);

$number = static fn (string $key, float $default): float => is_numeric($scenario[$key] ?? null) ? (float) $scenario[$key] : $default;

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$fixture = is_string($scenario['fixture'] ?? null) ? $scenario['fixture'] : null;

if (str_ends_with($path, '/responses')) {
    $fixture = 'openai/text.sse';
} elseif (str_ends_with($path, '/api/chat')) {
    $fixture = 'ollama/text.ndjson';
}

http_response_code((int) $number('status', 200));
header('Content-Type: text/plain');
header('X-Request-Id: req_stream_server');

while (ob_get_level() > 0) {
    ob_end_flush();
}

ob_implicit_flush(true);

usleep((int) ($number('first', 0) * 1_000_000));

$chunks = [];

if ($fixture !== null) {
    $contents = (string) file_get_contents(__DIR__.'/../Fixtures/'.basename(dirname($fixture)).'/'.basename($fixture));
    $chunks = str_split($contents, 64);
} else {
    for ($index = 0; $index < (int) $number('chunks', 3); $index++) {
        $chunks[] = "chunk-{$index}\n";
    }
}

foreach ($chunks as $index => $chunk) {
    echo $chunk;
    flush();

    if ($index === 0 && $number('silence', 0) > 0) {
        usleep((int) ($number('silence', 0) * 1_000_000));
    } else {
        usleep((int) ($number('gap', 0.02) * 1_000_000));
    }
}
