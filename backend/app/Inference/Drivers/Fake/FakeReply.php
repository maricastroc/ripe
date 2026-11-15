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
use JsonException;

final readonly class FakeReply
{
    private function __construct(
        private string $text,
        private FinishReason $finishReason = FinishReason::Stop,
        private ?InferenceException $failure = null,
        private ?Usage $usage = null,
        private int $silentTicks = 0,
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
        return new self($this->text, $this->finishReason, $this->failure, $this->usage, $ticks);
    }

    public function play(ModelRequest $request, CallSupervisor $supervisor, ?StreamObserver $observer, string $model): ModelResponse
    {
        $startedAt = hrtime(true);
        $streamed = '';

        try {
            for ($tick = 0; $tick < $this->silentTicks; $tick++) {
                $supervisor->tick();
            }

            foreach ($this->pieces() as $piece) {
                $supervisor->tick();
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
