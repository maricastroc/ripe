<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Groq;

use App\Inference\Streaming\ServerSentEvent;
use App\Inference\Support\Json;
use App\Inference\Usage;
use JsonException;

final class GroqStream
{
    private string $text = '';

    private ?string $finishReason = null;

    private ?string $model = null;

    private ?string $id = null;

    private ?Usage $usage = null;

    /** @var array<array-key, mixed>|null */
    private ?array $error = null;

    private bool $done = false;

    public function apply(ServerSentEvent $event): ?string
    {
        if (trim($event->data) === '[DONE]') {
            $this->done = true;

            return null;
        }

        try {
            $chunk = json_decode($event->data, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($chunk)) {
            return null;
        }

        if (is_array($chunk['error'] ?? null)) {
            $this->error = $chunk;

            return null;
        }

        $this->id ??= Json::string($chunk, 'id');
        $this->model ??= Json::string($chunk, 'model');
        $this->usage = GroqUsage::from($chunk) ?? $this->usage;
        $finish = Json::string($chunk, 'choices.0.finish_reason');
        $this->finishReason = $finish ?? $this->finishReason;
        $delta = Json::string($chunk, 'choices.0.delta.content');

        if ($delta === null || $delta === '') {
            return null;
        }

        $this->text .= $delta;

        return $delta;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function streamedCharacters(): int
    {
        return mb_strlen($this->text);
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function usage(): ?Usage
    {
        return $this->usage;
    }

    /** @return array<array-key, mixed>|null */
    public function error(): ?array
    {
        return $this->error;
    }

    public function finished(): bool
    {
        return $this->done || $this->finishReason !== null;
    }
}
