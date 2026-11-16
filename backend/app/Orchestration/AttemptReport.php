<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\ChargeBasis;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\InferenceException;
use App\Inference\ModelResponse;
use App\Inference\Usage;
use Throwable;

final readonly class AttemptReport
{
    public function __construct(
        public ?Usage $usage = null,
        public ?int $costMicrodollars = null,
        public ?string $requestId = null,
        public ?string $responseId = null,
        public ?string $model = null,
        public ?int $timeToFirstTokenMs = null,
        public ?int $durationMs = null,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
        public ?string $detail = null,
    ) {}

    public static function fromResponse(ModelResponse $response): self
    {
        return new self(
            usage: $response->usage,
            costMicrodollars: $response->costMicrodollars,
            requestId: $response->requestId,
            responseId: $response->responseId,
            model: $response->model,
            timeToFirstTokenMs: $response->timeToFirstTokenMs,
            durationMs: $response->durationMs,
        );
    }

    public static function fromFailure(Throwable $failure, ?string $detail = null): self
    {
        $details = $failure instanceof InferenceException || $failure instanceof CallAborted;

        return new self(
            usage: $details ? $failure->usage() : null,
            costMicrodollars: $details ? $failure->costMicrodollars() : null,
            requestId: $details ? $failure->requestId() : null,
            errorClass: class_basename($failure),
            errorMessage: mb_substr($failure->getMessage(), 0, 2000),
            detail: $detail,
        );
    }

    public static function withDetail(string $detail): self
    {
        return new self(detail: $detail);
    }

    public function billing(): ChargeBasis
    {
        if ($this->usage !== null && $this->costMicrodollars !== null) {
            return $this->usage->estimated ? ChargeBasis::Estimated : ChargeBasis::Reported;
        }

        return $this->usage === null && $this->requestId !== null ? ChargeBasis::Unbilled : ChargeBasis::Reservation;
    }
}
