<?php

declare(strict_types=1);

namespace App\Agent\Simulation;

use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\ModelRequest;

final class SimulatedModel
{
    public function __construct(private readonly float $pauseSeconds = 0.0) {}

    public function __invoke(ModelRequest $request): FakeReply
    {
        $input = $request->messages[0]->content ?? '';

        $reply = match ($request->schema?->name) {
            'interpretation' => FakeReply::json([
                'title' => $this->title($input),
                'language' => 'en',
                'deliverable' => 'document',
                'outcome' => 'A first complete version of the requested piece, ready to review.',
                'context' => [],
                'assumptions' => ['Plain, friendly language.'],
                'questions' => [['question' => 'Who will read it?', 'why' => 'It sets the tone.', 'kind' => 'optional']],
            ]),
            'plan' => FakeReply::json(['steps' => [
                ['title' => 'Outline', 'description' => 'The structure of the piece.', 'output' => 'outline', 'buildsOn' => [], 'requires' => []],
                ['title' => 'First draft', 'description' => 'A complete first draft.', 'output' => 'draft', 'buildsOn' => [1], 'requires' => []],
                ['title' => 'Final version', 'description' => 'The polished version.', 'output' => 'draft', 'buildsOn' => [1, 2], 'requires' => []],
            ]]),
            'review' => FakeReply::json([
                'verdict' => 'almost',
                'summary' => 'The simulated work is complete. One detail still depends on you.',
                'strengths' => ['Clear structure'],
                'issues' => [],
                'missing' => ['A detail only you can give'],
                'nextActions' => ['Fill in the marked gap'],
            ]),
            default => FakeReply::text($this->piece($input)),
        };

        return $this->pauseSeconds > 0 ? $reply->afterPause($this->pauseSeconds) : $reply;
    }

    private function title(string $input): string
    {
        if (preg_match('/"""\n(.+?)\n"""/s', $input, $matches) !== 1) {
            return 'Simulated task';
        }

        $words = preg_split('/\s+/u', trim($matches[1]), flags: PREG_SPLIT_NO_EMPTY) ?: ['Simulated', 'task'];

        return implode(' ', array_slice($words, 0, 6));
    }

    private function piece(string $input): string
    {
        $title = preg_match('/Now produce step \d+: "([^"]+)"/', $input, $matches) === 1
            ? $matches[1]
            : (preg_match('/Current material: "([^"]+)"/', $input, $current) === 1 ? "{$current[1]}, revised" : 'Piece');

        return "## {$title}\n\nA first version of {$title}, written by the simulated agent so the greenhouse can ripen without a real model. "
            .'It leaves one gap marked as [[TBD: a detail only you can give]] instead of inventing it, exactly as the real agent would.';
    }
}
