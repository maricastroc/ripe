<?php

declare(strict_types=1);

use App\Agent\AgentRequests;
use App\Agent\Brief;
use App\Agent\BriefQuestion;
use App\Agent\Prompts\CurrentStep;
use App\Agent\Prompts\Piece;
use App\Agent\Prompts\PlanLine;
use App\Agent\Prompts\PromptBook;
use App\Agent\Results\Interpretation;
use App\Agent\Results\Plan;
use App\Agent\Results\Review;
use App\Agent\StructuredOutput;
use App\Agent\Text\Markdown;
use App\Enums\QuestionStatus;
use App\Enums\RunKind;
use App\Inference\InferenceManager;

dataset('live drivers', ['ollama', 'groq', 'openai']);

it('interprets, plans, writes and reviews a real request', function (string $name): void {
    $driver = app(InferenceManager::class)->current($name);

    if (! $driver->health()->isAvailable()) {
        $this->markTestSkipped("{$name} is not available: start Ollama, or set GROQ_API_KEY, or OPENAI_API_KEY and OPENAI_MODEL.");
    }
    $book = new PromptBook;
    $requests = new AgentRequests;
    $structured = new StructuredOutput;
    $request = 'Escrever um bilhete de agradecimento para a vizinha que regou minhas plantas enquanto eu viajava.';

    $interpretPrompt = $book->interpret($request);
    $interpretation = $structured->decode($driver->generate($requests->build(RunKind::Interpret, $interpretPrompt))->text, $interpretPrompt->schema);
    expect($interpretation)->toBeInstanceOf(Interpretation::class);

    $brief = new Brief(
        prompt: $request,
        title: $interpretation->title,
        outcome: $interpretation->outcome,
        deliverable: $interpretation->deliverable,
        context: $interpretation->context,
        assumptions: $interpretation->assumptions,
        questions: array_values(array_map(static fn ($question, int $index): BriefQuestion => new BriefQuestion($index + 1, $question->question, $question->kind, QuestionStatus::Open), $interpretation->questions, array_keys($interpretation->questions))),
        language: $interpretation->language,
    );

    $planPrompt = $book->plan($brief);
    $plan = $structured->decode($driver->generate($requests->build(RunKind::Plan, $planPrompt))->text, $planPrompt->schema);
    expect($plan)->toBeInstanceOf(Plan::class)->and($plan->steps)->not->toBeEmpty();

    $first = $plan->steps[0];
    $piece = $driver->generate($requests->build(RunKind::Generate, $book->generate(
        $brief,
        array_map(static fn ($step): PlanLine => new PlanLine($step->number, $step->title, false), $plan->steps),
        new CurrentStep($first->number, $first->title, $first->description, $first->output),
        [],
    )));
    $content = Markdown::unfence($piece->text);
    expect(Markdown::words($content))->toBeGreaterThanOrEqual(15);

    $reviewPrompt = $book->review($brief, [new Piece($first->title, $content)]);
    $review = $structured->decode($driver->generate($requests->build(RunKind::Review, $reviewPrompt))->text, $reviewPrompt->schema);
    expect($review)->toBeInstanceOf(Review::class);

    fwrite(STDERR, sprintf(
        "\n[%s %s] language=%s deliverable=%s questions=%d steps=%d first piece=%d words gaps=%d verdict=%s tokens=%d/%d\n",
        $name,
        $driver->model(),
        $interpretation->language ?? '?',
        $interpretation->deliverable->value,
        count($interpretation->questions),
        count($plan->steps),
        Markdown::words($content),
        count(Markdown::gaps($content)),
        $review->verdict->value,
        $piece->usage->inputTokens,
        $piece->usage->outputTokens,
    ));
})->with('live drivers')->group('live');
