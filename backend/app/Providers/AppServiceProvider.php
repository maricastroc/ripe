<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\PostgresConnection;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use PDO;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('pgsql', static fn (PDO|Closure $connection, string $database, string $prefix, array $config): PostgresConnection => new PostgresConnection($connection, $database, $prefix, $config));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());
        Schema::defaultTimePrecision(6);
    }
}
