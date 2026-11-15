<?php

declare(strict_types=1);

namespace App\Snapshots;

use App\Enums\NoteKind;
use Carbon\CarbonImmutable;

final readonly class FieldNote
{
    public function __construct(
        public int $sequence,
        public NoteKind $kind,
        public string $message,
        public CarbonImmutable $at,
    ) {}
}
