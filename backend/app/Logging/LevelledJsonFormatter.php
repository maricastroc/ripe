<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

final class LevelledJsonFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        $normalized = $this->normalizeRecord($record);

        unset($normalized['level_name']);
        $normalized['level'] = match (true) {
            $record->level->isHigherThan(Level::Warning) => 'error',
            $record->level === Level::Warning => 'warn',
            $record->level === Level::Debug => 'debug',
            default => 'info',
        };

        return $this->toJson($normalized, true).($this->appendNewline ? "\n" : '');
    }
}
