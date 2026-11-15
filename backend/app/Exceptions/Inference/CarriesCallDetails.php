<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use App\Inference\Usage;

trait CarriesCallDetails
{
    private ?Usage $usage = null;

    private ?string $requestId = null;

    private ?int $costMicrodollars = null;

    public function withCallDetails(?Usage $usage, ?string $requestId = null, ?int $costMicrodollars = null): static
    {
        $this->usage = $usage;
        $this->requestId = $requestId;
        $this->costMicrodollars = $costMicrodollars;

        return $this;
    }

    public function usage(): ?Usage
    {
        return $this->usage;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function costMicrodollars(): ?int
    {
        return $this->costMicrodollars;
    }
}
