<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('ripe:reconcile')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('ripe:prune')->hourly()->withoutOverlapping()->onOneServer();
