<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

final class PrecisePostgresGrammar extends PostgresGrammar
{
    public const string DATE_FORMAT = 'Y-m-d H:i:s.uP';

    public function getDateFormat(): string
    {
        return self::DATE_FORMAT;
    }
}
