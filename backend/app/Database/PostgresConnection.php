<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;

final class PostgresConnection extends BasePostgresConnection
{
    protected function getDefaultQueryGrammar(): PrecisePostgresGrammar
    {
        return new PrecisePostgresGrammar($this);
    }
}
