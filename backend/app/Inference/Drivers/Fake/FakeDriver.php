<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Fake;

use App\Inference\Contracts\CallSupervisor;
use App\Inference\Contracts\ModelDriver;
use App\Inference\Contracts\StreamObserver;
use App\Inference\DriverHealth;
use App\Inference\ModelRequest;
use App\Inference\ModelResponse;
use App\Inference\NullSupervisor;
use Closure;
use LogicException;

final class FakeDriver implements ModelDriver
{
    /** @var list<FakeReply> */
    private array $replies;

    /** @var list<ModelRequest> */
    private array $sent = [];

    private DriverHealth $health;

    /**
     * @param  list<FakeReply>  $replies
     * @param  (Closure(ModelRequest): FakeReply)|null  $responder
     */
    private function __construct(array $replies, private readonly ?Closure $responder = null)
    {
        $this->replies = $replies;
        $this->health = DriverHealth::available(['fake-model']);
    }

    public static function replying(FakeReply ...$replies): self
    {
        return new self(array_values($replies));
    }

    /** @param Closure(ModelRequest): FakeReply $responder */
    public static function respondingWith(Closure $responder): self
    {
        return new self([], $responder);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function health(): DriverHealth
    {
        return $this->health;
    }

    public function reportHealth(DriverHealth $health): self
    {
        $this->health = $health;

        return $this;
    }

    public function maximumCost(ModelRequest $request): int
    {
        return 0;
    }

    public function generate(ModelRequest $request, ?CallSupervisor $supervisor = null, ?StreamObserver $observer = null): ModelResponse
    {
        $this->sent[] = $request;

        $reply = array_shift($this->replies)
            ?? ($this->responder === null ? null : ($this->responder)($request))
            ?? throw new LogicException('The fake model has no reply left for this request.');

        return $reply->play($request, $supervisor ?? new NullSupervisor, $observer, $this->model());
    }

    /** @return list<ModelRequest> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function pendingReplies(): int
    {
        return count($this->replies);
    }
}
