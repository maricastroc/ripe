<?php

declare(strict_types=1);

namespace App\Inference\Drivers\OpenAI;

use App\Inference\Streaming\ServerSentEvent;
use App\Inference\Support\Json;

final class OpenAIStream
{
    private ?string $responseId = null;

    private string $text = '';

    private string $refusal = '';

    private ?string $terminal = null;

    /** @var array<array-key, mixed> */
    private array $response = [];

    /** @var array<array-key, mixed> */
    private array $error = [];

    public function apply(ServerSentEvent $event): ?string
    {
        $payload = Json::decodeObject($event->data);

        if ($payload === null) {
            return null;
        }

        $type = Json::string($payload, 'type') ?? $event->event;
        $this->responseId ??= Json::string($payload, 'response.id');

        switch ($type) {
            case 'response.output_text.delta':
                $delta = Json::string($payload, 'delta') ?? '';
                $this->text .= $delta;

                return $delta === '' ? null : $delta;
            case 'response.refusal.delta':
                $this->refusal .= Json::string($payload, 'delta') ?? '';

                return null;
            case 'response.completed':
            case 'response.incomplete':
            case 'response.failed':
                $this->terminal = $type;
                $response = Json::get($payload, 'response');
                $this->response = is_array($response) ? $response : [];

                return null;
            case 'error':
                $this->terminal = 'error';
                $this->error = $payload;

                return null;
            default:
                return null;
        }
    }

    public function terminal(): ?string
    {
        return $this->terminal;
    }

    public function responseId(): ?string
    {
        return $this->responseId;
    }

    /** @return array<array-key, mixed> */
    public function response(): array
    {
        return $this->response;
    }

    /** @return array<array-key, mixed> */
    public function error(): array
    {
        return $this->error;
    }

    public function streamedCharacters(): int
    {
        return mb_strlen($this->text) + mb_strlen($this->refusal);
    }

    public function text(): string
    {
        $parts = $this->contentParts('output_text', 'text');

        return $parts === [] ? $this->text : implode('', $parts);
    }

    public function refusal(): string
    {
        $parts = $this->contentParts('refusal', 'refusal');

        return $parts === [] ? $this->refusal : implode('', $parts);
    }

    /** @return list<string> */
    private function contentParts(string $type, string $field): array
    {
        $parts = [];

        foreach (Json::list($this->response, 'output') as $item) {
            foreach (Json::list($item, 'content') as $content) {
                if (Json::string($content, 'type') === $type) {
                    $parts[] = Json::string($content, $field) ?? '';
                }
            }
        }

        return $parts;
    }
}
