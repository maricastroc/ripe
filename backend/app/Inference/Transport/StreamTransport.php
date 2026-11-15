<?php

declare(strict_types=1);

namespace App\Inference\Transport;

use App\Inference\Contracts\CallSupervisor;
use Closure;

interface StreamTransport
{
    /** @param Closure(string): void $onBody */
    public function send(StreamRequest $request, Closure $onBody, CallSupervisor $supervisor, Timeouts $timeouts): StreamResponse;
}
