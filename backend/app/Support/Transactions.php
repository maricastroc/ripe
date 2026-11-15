<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\TransactionRequired;
use Illuminate\Support\Facades\DB;

final class Transactions
{
    public static function require(string $operation): void
    {
        if (DB::transactionLevel() === 0) {
            throw TransactionRequired::for($operation);
        }
    }
}
