<?php

declare(strict_types=1);

namespace App\Inference\Transport;

use App\Enums\Inference\TimeoutKind;
use App\Exceptions\Inference\TransportFailed;
use App\Exceptions\Inference\TransportTimeout;
use App\Inference\Contracts\CallSupervisor;
use Closure;
use CurlHandle;
use CurlMultiHandle;
use Throwable;

final class CurlStreamTransport implements StreamTransport
{
    private const int ERROR_BODY_LIMIT = 65536;

    private int $status = 0;

    /** @var array<string, string> */
    private array $headers = [];

    private string $errorBody = '';

    private ?int $firstByteAt = null;

    private ?int $lastByteAt = null;

    private int $receivedBytes = 0;

    private ?Throwable $bodyFailure = null;

    public function send(StreamRequest $request, Closure $onBody, CallSupervisor $supervisor, Timeouts $timeouts): StreamResponse
    {
        $this->reset();
        $startedAt = self::nanoseconds();
        $handle = $this->handle($request, $onBody, $timeouts);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $handle);

        try {
            $this->pump($multi, $startedAt, $supervisor, $timeouts);
            $this->assertCompleted($multi, $handle);

            return new StreamResponse(
                status: $this->status,
                headers: $this->headers,
                errorBody: $this->errorBody,
                firstByteMs: $this->firstByteAt === null ? null : $this->milliseconds($startedAt, $this->firstByteAt),
                durationMs: $this->milliseconds($startedAt, self::nanoseconds()),
                receivedBytes: $this->receivedBytes,
            );
        } finally {
            curl_multi_remove_handle($multi, $handle);
            curl_multi_close($multi);
        }
    }

    /** @param Closure(string): void $onBody */
    private function handle(StreamRequest $request, Closure $onBody, Timeouts $timeouts): CurlHandle
    {
        $handle = curl_init($request->url);

        $headers = ['Expect:'];

        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($timeouts->connect * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => $this->onHeader(...),
            CURLOPT_WRITEFUNCTION => fn (CurlHandle $handle, string $chunk): int => $this->onChunk($chunk, $onBody),
        ]);

        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        return $handle;
    }

    private function pump(CurlMultiHandle $multi, int $startedAt, CallSupervisor $supervisor, Timeouts $timeouts): void
    {
        do {
            $code = curl_multi_exec($multi, $running);

            if ($code !== CURLM_OK) {
                throw new TransportFailed(curl_multi_strerror($code) ?? 'The HTTP client failed.', $code, $this->receivedBytes);
            }

            if ($this->bodyFailure !== null) {
                throw $this->bodyFailure;
            }

            $this->enforceDeadlines($startedAt, $timeouts);
            $supervisor->tick();

            if ($running > 0 && curl_multi_select($multi, $timeouts->tick) === -1) {
                usleep((int) ($timeouts->tick * 1_000_000));
            }
        } while ($running > 0);
    }

    private function enforceDeadlines(int $startedAt, Timeouts $timeouts): void
    {
        $now = self::nanoseconds();

        if ($this->seconds($startedAt, $now) >= $timeouts->total) {
            throw new TransportTimeout(TimeoutKind::Total, $this->receivedBytes);
        }

        if ($this->lastByteAt === null && $this->seconds($startedAt, $now) >= $timeouts->firstByte) {
            throw new TransportTimeout(TimeoutKind::FirstByte, $this->receivedBytes);
        }

        if ($this->lastByteAt !== null && $this->seconds($this->lastByteAt, $now) >= $timeouts->idle) {
            throw new TransportTimeout(TimeoutKind::Idle, $this->receivedBytes);
        }
    }

    private function assertCompleted(CurlMultiHandle $multi, CurlHandle $handle): void
    {
        while (($info = curl_multi_info_read($multi)) !== false) {
            $result = $info['result'] ?? null;

            if (! is_int($result) || $result === CURLE_OK) {
                continue;
            }

            if ($this->bodyFailure !== null) {
                throw $this->bodyFailure;
            }

            throw new TransportFailed(curl_error($handle) ?: curl_strerror($result) ?? 'The connection failed.', $result, $this->receivedBytes);
        }

        if ($this->bodyFailure !== null) {
            throw $this->bodyFailure;
        }
    }

    private function onHeader(CurlHandle $handle, string $line): int
    {
        $trimmed = trim($line);

        if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $trimmed, $matches) === 1) {
            $this->status = (int) $matches[1];
            $this->headers = [];
        } elseif (str_contains($trimmed, ':')) {
            [$name, $value] = explode(':', $trimmed, 2);
            $this->headers[strtolower(trim($name))] = trim($value);
        }

        return strlen($line);
    }

    /** @param Closure(string): void $onBody */
    private function onChunk(string $chunk, Closure $onBody): int
    {
        $now = self::nanoseconds();
        $this->firstByteAt ??= $now;
        $this->lastByteAt = $now;
        $this->receivedBytes += strlen($chunk);

        if ($this->status >= 400) {
            if (strlen($this->errorBody) < self::ERROR_BODY_LIMIT) {
                $this->errorBody .= $chunk;
            }

            return strlen($chunk);
        }

        try {
            $onBody($chunk);
        } catch (Throwable $failure) {
            $this->bodyFailure = $failure;

            return 0;
        }

        return strlen($chunk);
    }

    private function reset(): void
    {
        $this->status = 0;
        $this->headers = [];
        $this->errorBody = '';
        $this->firstByteAt = null;
        $this->lastByteAt = null;
        $this->receivedBytes = 0;
        $this->bodyFailure = null;
    }

    private static function nanoseconds(): int
    {
        $time = hrtime(true);

        return is_int($time) ? $time : (int) round($time);
    }

    private function seconds(int $from, int $to): float
    {
        return ($to - $from) / 1e9;
    }

    private function milliseconds(int $from, int $to): int
    {
        return intdiv($to - $from, 1_000_000);
    }
}
