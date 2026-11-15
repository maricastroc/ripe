<?php

declare(strict_types=1);

namespace App\Orchestration\Output;

use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Realtime\Relay;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

final class PartialOutput
{
    public function __construct(private readonly Relay $relay) {}

    public function open(AgentRun $run, RunAttempt $attempt): ?PartialOutputWriter
    {
        if (! self::streams($run)) {
            return null;
        }

        $current = $this->store()->get($this->pointerKey($run->id));

        if (! is_int($current) || $current < $attempt->number) {
            $this->store()->put($this->pointerKey($run->id), $attempt->number, $this->ttl());
        }

        return new PartialOutputWriter($this, $this->relay, $run->task_id, $run->id, $attempt->id, $attempt->number, Config::float('ripe.output.flush_interval'), Config::integer('ripe.output.max_characters'));
    }

    public function write(string $runId, string $attemptId, int $number, string $text): bool
    {
        $current = $this->store()->get($this->pointerKey($runId));

        if (is_int($current) && $current > $number) {
            return false;
        }

        $this->store()->put($this->textKey($attemptId), ['text' => $text, 'length' => mb_strlen($text)], $this->ttl());

        return true;
    }

    public function current(AgentRun $run): ?LiveText
    {
        if ($run->status !== RunStatus::Running || $run->lease_token === null || ! self::streams($run)) {
            return null;
        }

        $attempt = RunAttempt::query()->where('run_id', $run->id)->where('lease_token', $run->lease_token)->whereNull('finished_at')->first();

        return $attempt === null ? null : $this->of($attempt) ?? new LiveText($attempt->id, $attempt->number, '');
    }

    public function of(RunAttempt $attempt): ?LiveText
    {
        $current = $this->store()->get($this->pointerKey($attempt->run_id));
        $stored = $this->store()->get($this->textKey($attempt->id));

        if ((is_int($current) && $current > $attempt->number) || ! is_array($stored) || ! is_string($stored['text'] ?? null)) {
            return null;
        }

        return new LiveText($attempt->id, $attempt->number, $stored['text']);
    }

    public function forget(string $attemptId): void
    {
        $this->store()->forget($this->textKey($attemptId));
    }

    private static function streams(AgentRun $run): bool
    {
        return in_array($run->kind, [RunKind::Generate, RunKind::Revise], true);
    }

    private function store(): Repository
    {
        $store = Config::get('ripe.output.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private function ttl(): int
    {
        return Config::integer('ripe.output.ttl_seconds');
    }

    private function pointerKey(string $runId): string
    {
        return "ripe:output:run:{$runId}";
    }

    private function textKey(string $attemptId): string
    {
        return "ripe:output:attempt:{$attemptId}";
    }
}
