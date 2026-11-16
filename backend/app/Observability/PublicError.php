<?php

declare(strict_types=1);

namespace App\Observability;

use App\Enums\AttemptOutcome;

final class PublicError
{
    public static function of(?AttemptOutcome $outcome, ?string $errorClass, ?string $locale = null): ?string
    {
        if ($outcome === null || $outcome === AttemptOutcome::Succeeded) {
            return null;
        }

        return self::sentence($outcome, $errorClass, $locale);
    }

    private static function sentence(AttemptOutcome $outcome, ?string $errorClass, ?string $locale): string
    {
        return match ($outcome) {
            AttemptOutcome::Succeeded => __('Something went wrong on our side.', [], $locale),
            AttemptOutcome::Cancelled => __('Stopped before it finished.', [], $locale),
            AttemptOutcome::LeaseLost, AttemptOutcome::Interrupted => __('The work stopped halfway and was picked up again.', [], $locale),
            AttemptOutcome::TimedOut => __('The model took too long to answer.', [], $locale),
            AttemptOutcome::RateLimited => __('The model asked for a pause.', [], $locale),
            AttemptOutcome::InvalidOutput => $errorClass === 'InvalidModelOutput' ? __('The model’s answer was not in the expected shape.', [], $locale) : __('Something went wrong on our side.', [], $locale),
            AttemptOutcome::DriverUnavailable, AttemptOutcome::Failed => self::byClass($errorClass, $locale),
        };
    }

    private static function byClass(?string $errorClass, ?string $locale): string
    {
        return match ($errorClass) {
            'ModelUnavailable', 'TransportFailed', 'TransportTimeout' => __('The model could not be reached.', [], $locale),
            'DriverMisconfigured' => __('The model is not configured correctly.', [], $locale),
            'ProviderQuotaExhausted' => __('The model’s allowance is used up.', [], $locale),
            'ModelRefused' => __('The model declined to write this.', [], $locale),
            'ModelRequestRejected' => __('The model rejected the request.', [], $locale),
            'RateLimited' => __('The model asked for a pause.', [], $locale),
            'ModelTimeout' => __('The model took too long to answer.', [], $locale),
            'InvalidModelOutput' => __('The model’s answer was not in the expected shape.', [], $locale),
            default => __('Something went wrong on our side.', [], $locale),
        };
    }
}
