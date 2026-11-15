<?php

declare(strict_types=1);

namespace App\Agent;

final class Limits
{
    public const int MAX_STEPS = 5;

    public const int MAX_QUESTIONS = 4;

    public const int MAX_CONTEXT_FACTS = 8;

    public const int MAX_ASSUMPTIONS = 3;

    public const int MAX_PROMPT_CHARACTERS = 2000;

    public const int CONTEXT_CHARACTERS_PER_PIECE = 1800;

    public const int REVISION_CHARACTERS = 6000;

    public const int MAX_TITLE_CHARACTERS = 80;

    public const int MAX_STEP_TITLE_CHARACTERS = 90;

    public const int MIN_PIECE_WORDS = 15;
}
