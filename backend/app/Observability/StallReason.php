<?php

declare(strict_types=1);

namespace App\Observability;

use App\Enums\RunKind;
use Illuminate\Support\Facades\App;

final class StallReason
{
    public const string STORED_LOCALE = 'en';

    public static function compose(RunKind $kind, ?int $attempts, ?string $reason): string
    {
        $replace = ['kind' => ucfirst($kind->value), 'attempts' => $attempts, 'reason' => $reason ?? __('it failed.', [], self::STORED_LOCALE)];

        return $attempts === null
            ? __(':kind: :reason', $replace, self::STORED_LOCALE)
            : __(':kind failed after :attempts attempts. :reason', $replace, self::STORED_LOCALE);
    }

    public static function present(?string $stored): ?string
    {
        if ($stored === null || App::isLocale(self::STORED_LOCALE)) {
            return $stored;
        }

        if (preg_match('/\A(\w+) failed after (\d+) attempts\. (.+)\z/su', $stored, $parts) === 1) {
            return __(':kind failed after :attempts attempts. :reason', ['kind' => self::kind($parts[1]), 'attempts' => $parts[2], 'reason' => __($parts[3])]);
        }

        if (preg_match('/\A(\w+): (.+)\z/su', $stored, $parts) === 1) {
            return __(':kind: :reason', ['kind' => self::kind($parts[1]), 'reason' => __($parts[2])]);
        }

        return __($stored);
    }

    private static function kind(string $label): string
    {
        return match (RunKind::tryFrom(strtolower($label))) {
            RunKind::Interpret => __('Interpret'),
            RunKind::Plan => __('Plan'),
            RunKind::Generate => __('Generate'),
            RunKind::Revise => __('Revise'),
            RunKind::Review => __('Review'),
            null => $label,
        };
    }
}
