<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use App\Enums\Inference\ErrorScope;

final class ModelRefused extends InferenceException
{
    public function scope(): ErrorScope
    {
        return ErrorScope::Run;
    }
}
