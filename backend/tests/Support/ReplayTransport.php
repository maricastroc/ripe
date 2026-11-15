<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Inference\Contracts\CallSupervisor;
use App\Inference\Transport\StreamRequest;
use App\Inference\Transport\StreamResponse;
use App\Inference\Transport\StreamTransport;
use App\Inference\Transport\Timeouts;
use Closure;
use RuntimeException;
use Throwable;

final class ReplayTransport implements StreamTransport
{
    public ?StreamRequest $lastRequest = null;

    public int $ticks = 0;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = '',
        private readonly array $headers = [],
        private readonly ?Throwable $failure = null,
        private readonly ?int $failAtByte = null,
        private readonly int $chunkSize = 7,
    ) {}

    /** @param array<string, string> $headers */
    public static function fixture(string $name, array $headers = ['x-request-id' => 'req_replay']): self
    {
        return new self(body: self::read($name), headers: $headers);
    }

    /** @param array<string, string> $headers */
    public static function error(int $status, string $body = '', array $headers = []): self
    {
        return new self(status: $status, body: $body, headers: $headers);
    }

    public static function failing(Throwable $failure, ?string $fixture = null, int $atByte = 0): self
    {
        return new self(body: $fixture === null ? '' : self::read($fixture), failure: $failure, failAtByte: $atByte);
    }

    public static function read(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/'.$name);

        if ($contents === false) {
            throw new RuntimeException("Missing fixture {$name}.");
        }

        return $contents;
    }

    public function send(StreamRequest $request, Closure $onBody, CallSupervisor $supervisor, Timeouts $timeouts): StreamResponse
    {
        $this->lastRequest = $request;
        $headers = array_change_key_case($this->headers, CASE_LOWER);

        if ($this->status >= 400) {
            return new StreamResponse($this->status, $headers, $this->body, 3, 5, strlen($this->body));
        }

        $received = 0;

        foreach ($this->body === '' ? [] : str_split($this->body, $this->chunkSize) as $chunk) {
            if ($this->failure !== null && $received >= ($this->failAtByte ?? PHP_INT_MAX)) {
                throw $this->failure;
            }

            $supervisor->tick();
            $this->ticks++;
            $onBody($chunk);
            $received += strlen($chunk);
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new StreamResponse($this->status, $headers, '', 3, 20, $received);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $decoded = json_decode($this->lastRequest->body ?? '{}', true);

        return is_array($decoded) ? $decoded : [];
    }
}
