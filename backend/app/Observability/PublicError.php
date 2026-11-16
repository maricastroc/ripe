<?php

declare(strict_types=1);

namespace App\Observability;

use App\Enums\AttemptOutcome;

final class PublicError
{
    private const string SOMETHING_WENT_WRONG = 'Something went wrong on our side.';

    public static function of(?AttemptOutcome $outcome, ?string $errorClass): ?string
    {
        return match ($outcome) {
            null, AttemptOutcome::Succeeded => null,
            AttemptOutcome::Cancelled => 'Stopped before it finished.',
            AttemptOutcome::LeaseLost, AttemptOutcome::Interrupted => 'The work stopped halfway and was picked up again.',
            AttemptOutcome::TimedOut => 'The model took too long to answer.',
            AttemptOutcome::RateLimited => 'The model asked for a pause.',
            AttemptOutcome::InvalidOutput => $errorClass === 'InvalidModelOutput' ? 'The model’s answer was not in the expected shape.' : self::SOMETHING_WENT_WRONG,
            AttemptOutcome::DriverUnavailable, AttemptOutcome::Failed => self::byClass($errorClass),
        };
    }

    private static function byClass(?string $errorClass): string
    {
        return match ($errorClass) {
            'ModelUnavailable', 'TransportFailed', 'TransportTimeout' => 'The model could not be reached.',
            'DriverMisconfigured' => 'The model is not configured correctly.',
            'ProviderQuotaExhausted' => 'The model’s allowance is used up.',
            'ModelRefused' => 'The model declined to write this.',
            'ModelRequestRejected' => 'The model rejected the request.',
            'RateLimited' => 'The model asked for a pause.',
            'ModelTimeout' => 'The model took too long to answer.',
            'InvalidModelOutput' => 'The model’s answer was not in the expected shape.',
            default => self::SOMETHING_WENT_WRONG,
        };
    }
}
