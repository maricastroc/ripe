<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use App\Enums\Inference\ErrorScope;

final class InvalidModelOutput extends InferenceException
{
    public function scope(): ErrorScope
    {
        return ErrorScope::Attempt;
    }
}
