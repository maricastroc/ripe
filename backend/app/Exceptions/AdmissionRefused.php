<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Orchestration\Budget\Shortfall;
use App\Orchestration\Quota\QuotaShortfall;
use RuntimeException;

final class AdmissionRefused extends RuntimeException
{
    public function __construct(public readonly QuotaShortfall|Shortfall $refusal)
    {
        parent::__construct('The call was not admitted.');
    }
}
