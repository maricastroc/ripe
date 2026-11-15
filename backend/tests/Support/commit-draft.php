<?php

declare(strict_types=1);

use App\Actions\Artifacts\CommitDraft;
use App\Exceptions\VersionConflict;
use App\Models\Artifact;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $artifactId, $baseVersionId, $revision, $startAt] = $argv;

if ((float) $startAt > microtime(true)) {
    time_sleep_until((float) $startAt);
}

try {
    $version = $app->make(CommitDraft::class)->handle(Artifact::query()->findOrFail($artifactId), $baseVersionId, (int) $revision);
    echo "kept {$version->number}";
} catch (VersionConflict) {
    echo 'conflict';
}
