<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(static function (): void {
            foreach (array_keys(DB::getConnections()) as $connection) {
                DB::disconnect($connection);
            }
        });
    }
}
