<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class StreamServer
{
    private static ?Process $process = null;

    private static int $port = 0;

    public static function start(): void
    {
        if (self::$process?->isRunning() === true) {
            return;
        }

        self::$port = self::freePort();
        self::$process = new Process([PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/stream-server.php']);
        self::$process->disableOutput();
        self::$process->start();

        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $code, $message, 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The stream server did not start.');
    }

    public static function stop(): void
    {
        self::$process?->stop(0.2);
        self::$process = null;
    }

    /** @param array<string, string|int|float> $scenario */
    public static function url(array $scenario = []): string
    {
        return 'http://127.0.0.1:'.self::$port.'/stream?'.http_build_query($scenario);
    }

    public static function baseUrl(): string
    {
        return 'http://127.0.0.1:'.self::$port;
    }

    public static function closedPortUrl(): string
    {
        return 'http://127.0.0.1:'.self::freePort().'/stream';
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if ($server === false) {
            throw new RuntimeException('No free port available.');
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
