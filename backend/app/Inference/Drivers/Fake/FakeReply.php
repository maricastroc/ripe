<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Fake;

use App\Enums\Inference\FinishReason;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Inference\Contracts\CallSupervisor;
use App\Inference\Contracts\StreamObserver;
use App\Inference\ModelRequest;
use App\Inference\ModelResponse;
use App\Inference\Usage;
use Closure;
use JsonException;

final readonly class FakeReply
{
    /** @param array<int, Closure(): void> $tickActions */
    private function __construct(
        private string $text,
        private FinishReason $finishReason = FinishReason::Stop,
        private ?InferenceException $failure = null,
        private ?Usage $usage = null,
        private int $silentTicks = 0,
        private float $pauseSeconds = 0.0,
        private array $tickActions = [],
    ) {}

    public static function text(string $text, FinishReason $finishReason = FinishReason::Stop, ?Usage $usage = null): self
    {
        return new self($text, $finishReason, usage: $usage);
    }

    /** @param array<array-key, mixed> $data */
    public static function json(array $data, ?Usage $usage = null): self
    {
        try {
            return new self(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), usage: $usage);
        } catch (JsonException $exception) {
            throw new InvalidModelOutput($exception->getMessage());
        }
    }

    public static function failure(InferenceException $failure): self
    {
        return new self('', failure: $failure);
    }

    public static function partialThenFailure(string $partial, InferenceException $failure): self
    {
        return new self($partial, failure: $failure);
    }

    public function afterSilence(int $ticks): self
    {
        return new self($this->text, $this->finishReason, $this->failure, $this->usage, $ticks, $this->pauseSeconds, $this->tickActions);
    }

    public function afterPause(float $seconds): self
    {
        return new self($this->text, $this->finishReason, $this->failure, $this->usage, $this->silentTicks, $seconds, $this->tickActions);
    }

    /** @param Closure(): void $action */
    public function onTick(int $tick, Closure $action): self
    {
        return new self($this->text, $this->finishReason, $this->failure, $this->usage, $this->silentTicks, $this->pauseSeconds, [$tick => $action] + $this->tickActions);
    }

    public function play(ModelRequest $request, CallSupervisor $supervisor, ?StreamObserver $observer, string $model): ModelResponse
    {
        $startedAt = hrtime(true);
        $streamed = '';
        $ticks = 0;
        $tick = function () use ($supervisor, &$ticks): void {
            $ticks++;
            ($this->tickActions[$ticks] ?? null)?->__invoke();
            $supervisor->tick();
        };

        try {
            $pauseUntil = microtime(true) + $this->pauseSeconds;

            while (microtime(true) < $pauseUntil) {
                $tick();
                usleep(100_000);
            }

            for ($silence = 0; $silence < $this->silentTicks; $silence++) {
                $tick();
            }

            foreach ($this->pieces() as $piece) {
                $tick();
                $streamed .= $piece;
                $observer?->onDelta($piece);
            }
        } catch (CallAborted $abort) {
            throw $abort->withCallDetails(Usage::estimate($request->inputCharacters(), mb_strlen($streamed)), null, 0);
        }

        if ($this->failure !== null) {
            throw $this->failure->withCallDetails($streamed === '' ? null : Usage::estimate($request->inputCharacters(), mb_strlen($streamed)), null, 0);
        }

        $usage = $this->usage ?? new Usage(
            inputTokens: (int) ceil($request->inputCharacters() / Usage::CHARACTERS_PER_TOKEN),
            outputTokens: (int) ceil(mb_strlen($this->text) / Usage::CHARACTERS_PER_TOKEN),
        );

        if ($this->finishReason === FinishReason::Length && $request->isStructured()) {
            throw (new InvalidModelOutput('The response was cut by the output limit before it was complete.'))->withCallDetails($usage, null, 0);
        }

        return new ModelResponse(
            text: $this->text,
            finishReason: $this->finishReason,
            usage: $usage,
            driver: 'fake',
            model: $model,
            costMicrodollars: 0,
            requestId: null,
            responseId: null,
            timeToFirstTokenMs: 0,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }

    /** @return list<string> */
    private function pieces(): array
    {
        if ($this->text === '') {
            return [];
        }

        $pieces = preg_split('/(?<=\s)/u', $this->text, flags: PREG_SPLIT_NO_EMPTY);

        return $pieces === false ? [$this->text] : $pieces;
    }
}
