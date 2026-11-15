<?php

declare(strict_types=1);

namespace App\Agent;

use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;

final readonly class BriefQuestion
{
    public function __construct(
        public int $number,
        public string $text,
        public QuestionKind $kind,
        public QuestionStatus $status,
        public ?string $answer = null,
    ) {}

    public function isAnswered(): bool
    {
        return $this->status === QuestionStatus::Answered && $this->answer !== null;
    }

    public function isOpenEssential(): bool
    {
        return $this->status === QuestionStatus::Open && $this->kind === QuestionKind::Essential;
    }
}
