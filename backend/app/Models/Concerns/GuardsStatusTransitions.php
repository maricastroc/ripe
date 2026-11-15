<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\States\StateMachine;
use Illuminate\Database\Eloquent\Model;

trait GuardsStatusTransitions
{
    public static function bootGuardsStatusTransitions(): void
    {
        static::updating(static function (Model $model): void {
            if (! $model->isDirty('status')) {
                return;
            }

            $from = $model->getOriginal('status');
            $to = $model->getAttribute('status');

            if ($from instanceof StateMachine && $to instanceof StateMachine) {
                $from->assertCanTransitionTo($to);
            }
        });
    }
}
