<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Feature');
pest()->extend(TestCase::class)->in('Integration');

function expectConstraintViolation(Closure $change, string $constraint): void
{
    try {
        $change();
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain("\"{$constraint}\"");

        return;
    }

    throw new AssertionFailedError("Expected {$constraint} to reject the change.");
}
