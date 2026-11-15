<?php

declare(strict_types=1);

namespace App\Examples;

use App\Actions\Tasks\AnswerQuestion;
use App\Actions\Tasks\PlantTask;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\InferenceManager;
use App\Models\AgentRun;
use App\Models\Question;
use App\Models\Task;
use App\Models\Visitor;
use App\Orchestration\Execution\RunExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ExampleGarden
{
    private const int MAX_RUNS = 40;

    public function __construct(
        private PlantTask $plant,
        private RunExecutor $executor,
        private InferenceManager $inference,
        private AnswerQuestion $answers,
    ) {}

    public function ensure(): Task
    {
        $existing = $this->existing();

        if ($existing !== null) {
            return $existing;
        }

        $lock = Cache::lock('ripe:examples:community-garden', 60);
        $lock->block(30);

        try {
            return $this->existing() ?? $this->grow();
        } finally {
            $lock->release();
        }
    }

    public function existing(): ?Task
    {
        return Task::query()->where('is_example', true)->where('prompt', CommunityGarden::PROMPT)->first();
    }

    private function grow(): Task
    {
        $gardener = Visitor::query()->create(['token_hash' => Visitor::hashToken(Str::random(64)), 'last_seen_at' => CarbonImmutable::now()]);
        $task = $this->plant->handle($gardener, CommunityGarden::PROMPT);
        $script = new CommunityGarden;

        $this->inference->using(FakeDriver::respondingWith($script->reply(...)), function () use ($task): void {
            for ($round = 0; $round < self::MAX_RUNS; $round++) {
                $run = AgentRun::query()->where('task_id', $task->id)->where('status', RunStatus::Queued)->orderBy('created_at')->orderBy('id')->first();

                if ($run === null) {
                    return;
                }

                $run->wake(CarbonImmutable::now());
                $this->executor->execute($run->id);
            }
        });

        if ($task->refresh()->status !== TaskStatus::Ripe) {
            throw new RuntimeException("The example stopped growing as {$task->status->value}.");
        }

        $this->answers->skip(Question::query()->where('task_id', $task->id)->where('position', 0)->firstOrFail());

        DB::transaction(static function () use ($task, $gardener): void {
            Task::query()->whereKey($task->id)->update(['is_example' => true, 'visitor_id' => null]);
            $gardener->delete();
        });

        return $task->refresh();
    }
}
