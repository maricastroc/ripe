<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch()->preset()->laravel();

arch('application code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();
