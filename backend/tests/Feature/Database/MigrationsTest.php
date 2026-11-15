<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('rolls every migration back and applies it again', function (): void {
    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('tasks'))->toBeFalse()
        ->and(Schema::hasTable('artifact_versions'))->toBeFalse()
        ->and(Schema::hasTable('visitors'))->toBeFalse();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('tasks'))->toBeTrue()
        ->and(Schema::hasTable('artifact_versions'))->toBeTrue()
        ->and(Schema::hasTable('visitors'))->toBeTrue();
});
