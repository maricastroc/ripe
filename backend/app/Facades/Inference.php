<?php

declare(strict_types=1);

namespace App\Facades;

use App\Inference\Contracts\ModelDriver;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\InferenceManager;
use Illuminate\Support\Facades\Facade;

/** @method static ModelDriver current(?string $name = null) */
final class Inference extends Facade
{
    public static function fake(FakeReply ...$replies): FakeDriver
    {
        return self::manager()->useFake(FakeDriver::replying(...$replies));
    }

    public static function fakeWith(FakeDriver $fake): FakeDriver
    {
        return self::manager()->useFake($fake);
    }

    protected static function getFacadeAccessor(): string
    {
        return InferenceManager::class;
    }

    private static function manager(): InferenceManager
    {
        $manager = self::getFacadeRoot();

        return $manager instanceof InferenceManager ? $manager : app(InferenceManager::class);
    }
}
