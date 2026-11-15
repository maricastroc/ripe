<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Ollama;

use App\Inference\Support\Json;
use App\Inference\Usage;

final class OllamaStream
{
    private string $text = '';

    private bool $done = false;

    private ?string $doneReason = null;

    private ?string $error = null;

    private ?Usage $usage = null;

    public function apply(string $line): ?string
    {
        $chunk = Json::decodeObject($line);

        if ($chunk === null) {
            return null;
        }

        $this->error ??= Json::string($chunk, 'error');
        $delta = Json::string($chunk, 'message.content') ?? '';
        $this->text .= $delta;

        if (Json::get($chunk, 'done') === true) {
            $this->done = true;
            $this->doneReason = Json::string($chunk, 'done_reason');
            $this->usage = new Usage(
                inputTokens: Json::integer($chunk, 'prompt_eval_count'),
                outputTokens: Json::integer($chunk, 'eval_count'),
            );
        }

        return $delta === '' ? null : $delta;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function doneReason(): ?string
    {
        return $this->doneReason;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function usage(): ?Usage
    {
        return $this->usage;
    }
}
