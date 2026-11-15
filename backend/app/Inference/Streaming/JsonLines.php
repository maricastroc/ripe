<?php

declare(strict_types=1);

namespace App\Inference\Streaming;

final class JsonLines
{
    private string $buffer = '';

    /** @return list<string> */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $lines = [];

        while (($end = strpos($this->buffer, "\n")) !== false) {
            $line = trim(substr($this->buffer, 0, $end));
            $this->buffer = substr($this->buffer, $end + 1);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    public function finish(): array
    {
        $rest = trim($this->buffer);
        $this->buffer = '';

        return $rest === '' ? [] : [$rest];
    }
}
