<?php

declare(strict_types=1);

namespace App\Inference\Streaming;

final class ServerSentEvents
{
    private string $buffer = '';

    private ?string $event = null;

    /** @var list<string> */
    private array $data = [];

    /** @return list<ServerSentEvent> */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (($end = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $end), "\r");
            $this->buffer = substr($this->buffer, $end + 1);

            if ($line === '') {
                if ($this->data !== []) {
                    $events[] = new ServerSentEvent($this->event, implode("\n", $this->data));
                }

                $this->event = null;
                $this->data = [];

                continue;
            }

            if (str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = str_contains($line, ':') ? explode(':', $line, 2) : [$line, ''];

            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }

            if ($field === 'event') {
                $this->event = $value;
            } elseif ($field === 'data') {
                $this->data[] = $value;
            }
        }

        return $events;
    }

    public function hasUnfinishedEvent(): bool
    {
        return $this->data !== [] || trim($this->buffer) !== '';
    }
}
