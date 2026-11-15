<?php

declare(strict_types=1);

use App\Agent\Brief;
use App\Agent\BriefQuestion;
use App\Agent\Prompts\CurrentStep;
use App\Agent\Prompts\Piece;
use App\Agent\Prompts\PlanLine;
use App\Agent\Prompts\PromptBook;
use App\Agent\Schemas\InterpretationSchema;
use App\Agent\Schemas\PlanSchema;
use App\Agent\Schemas\ReviewSchema;
use App\Enums\Deliverable;
use App\Enums\OutputFormat;
use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;

function brief(): Brief
{
    return new Brief(
        prompt: 'Responder o e-mail difícil do cliente sobre o novo prazo do projeto.',
        title: 'Resposta ao cliente',
        outcome: 'Uma resposta educada e firme sobre o prazo.',
        deliverable: Deliverable::Message,
        context: ['O cliente pediu entrega em duas semanas.'],
        assumptions: ['Tom profissional.'],
        questions: [
            new BriefQuestion(1, 'Qual o nome do cliente?', QuestionKind::Optional, QuestionStatus::Answered, 'Marta'),
            new BriefQuestion(2, 'Você aceita o novo prazo?', QuestionKind::Essential, QuestionStatus::Open),
            new BriefQuestion(3, 'Há algum desconto possível?', QuestionKind::Optional, QuestionStatus::Skipped),
        ],
        language: 'pt-BR',
    );
}

it('writes the interpretation prompt', function (): void {
    $prompt = (new PromptBook)->interpret('Responder o e-mail difícil do cliente.');

    expect($prompt->schema)->toBeInstanceOf(InterpretationSchema::class)
        ->and($prompt->instructions)->toMatchSnapshot()
        ->and($prompt->input)->toMatchSnapshot();
});

it('writes the plan prompt with the open essential questions numbered', function (): void {
    $prompt = (new PromptBook)->plan(brief());

    expect($prompt->schema)->toBeInstanceOf(PlanSchema::class)
        ->and($prompt->input)->toContain("Essential questions still open:\n2. Você aceita o novo prazo?")
        ->and($prompt->input)->toContain('Language of the request: pt-BR. Write everything in this language.')
        ->and($prompt->input)->toMatchSnapshot();

    $plan = $prompt->schema?->parse(['steps' => [
        ['title' => 'Pontos-chave', 'description' => 'd', 'output' => 'notes', 'buildsOn' => [], 'requires' => [1, 2]],
        ['title' => 'Resposta', 'description' => 'd', 'output' => 'message', 'buildsOn' => [1], 'requires' => [2]],
    ]]);

    expect($plan?->steps[0]->requires)->toBe([])
        ->and($plan?->steps[1]->requires)->toBe([2]);
});

it('writes the generation prompt', function (): void {
    $prompt = (new PromptBook)->generate(
        brief(),
        [new PlanLine(1, 'Pontos-chave', true), new PlanLine(2, 'Resposta', false)],
        new CurrentStep(2, 'Resposta', 'A resposta completa.', OutputFormat::Message),
        [new Piece('Pontos-chave', str_repeat('Ponto importante. ', 150))],
    );

    expect($prompt->schema)->toBeNull()
        ->and($prompt->input)->toContain("1. Pontos-chave  (done)\n2. Resposta  <- current step")
        ->and($prompt->input)->toContain("\n[...]")
        ->and($prompt->input)->toMatchSnapshot();
});

it('writes the review prompt', function (): void {
    $prompt = (new PromptBook)->review(brief(), [new Piece('Resposta', 'Olá Marta, [[TBD: decisão sobre o prazo]].')]);

    expect($prompt->schema)->toBeInstanceOf(ReviewSchema::class)
        ->and($prompt->input)->toMatchSnapshot();
});

it('writes the revision prompt, with the default request when none is given', function (): void {
    $book = new PromptBook;

    expect($book->revise(brief(), 'Resposta', 'Texto.', 'Mais curto.')->input)->toContain('Rewrite request: Mais curto.')
        ->and($book->revise(brief(), 'Resposta', 'Texto.')->input)->toContain('Rewrite request: '.PromptBook::DEFAULT_REVISION)
        ->and($book->revise(brief(), 'Resposta', 'Texto.', 'Mais curto.')->input)->toMatchSnapshot();
});

it('lists skipped questions as still unanswered, as the original agent did', function (): void {
    expect((new PromptBook)->plan(brief())->input)->toContain("Still unanswered (use [[TBD: ...]] where needed):\n- Você aceita o novo prazo?\n- Há algum desconto possível?");
});
