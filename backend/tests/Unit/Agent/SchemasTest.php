<?php

declare(strict_types=1);

use App\Agent\Results\Plan;
use App\Agent\Results\PlannedStep;
use App\Agent\Schemas\InterpretationSchema;
use App\Agent\Schemas\PlanSchema;
use App\Agent\Schemas\ReviewSchema;
use App\Agent\Schemas\StrictSchema;
use App\Agent\StructuredOutput;
use App\Enums\Deliverable;
use App\Enums\OutputFormat;
use App\Enums\QuestionKind;
use App\Enums\ReviewVerdict;
use App\Exceptions\Inference\InvalidModelOutput;

it('writes every schema in the subset both providers accept strictly', function (object $schema): void {
    expect(StrictSchema::violations($schema->format()->schema))->toBe([]);
})->with([
    'interpretation' => fn () => new InterpretationSchema,
    'plan' => fn () => new PlanSchema([1]),
    'review' => fn () => new ReviewSchema,
]);

it('reads an interpretation and keeps the agreed limits', function (): void {
    $result = (new InterpretationSchema)->parse([
        'title' => '  Thank-you note  ',
        'language' => 'pt-BR',
        'deliverable' => 'message',
        'outcome' => 'A short note.',
        'context' => ['a', '', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'],
        'assumptions' => ['x', 'y', 'z', 'w'],
        'questions' => [
            ['question' => 'Name?', 'why' => 'Greeting.', 'kind' => 'optional'],
            ['question' => '', 'why' => '', 'kind' => 'optional'],
            ['question' => 'Accept the deadline?', 'why' => 'Decides the reply.', 'kind' => 'essential'],
            ['question' => 'Q3', 'why' => '', 'kind' => 'optional'],
            ['question' => 'Q4', 'why' => '', 'kind' => 'optional'],
            ['question' => 'Q5', 'why' => '', 'kind' => 'optional'],
        ],
    ]);

    expect($result->title)->toBe('Thank-you note')
        ->and($result->language)->toBe('pt-BR')
        ->and($result->deliverable)->toBe(Deliverable::Message)
        ->and($result->context)->toHaveCount(8)
        ->and($result->assumptions)->toBe(['x', 'y', 'z'])
        ->and($result->questions)->toHaveCount(4)
        ->and($result->questions[1]->kind)->toBe(QuestionKind::Essential);
});

it('rejects an interpretation without the essentials', function (): void {
    expect(fn () => (new InterpretationSchema)->parse(['title' => '', 'deliverable' => 'poem', 'outcome' => 'O', 'context' => 'none', 'assumptions' => [], 'questions' => []]))
        ->toThrow(InvalidModelOutput::class, '$.title must be a non-empty string; $.deliverable has an unexpected value; $.context must be a list');
});

function stepEntry(string $title, array $buildsOn = [], array $requires = []): array
{
    return ['title' => $title, 'description' => "{$title}.", 'output' => 'draft', 'buildsOn' => $buildsOn, 'requires' => $requires];
}

function shape(Plan $plan): array
{
    return array_map(static fn (PlannedStep $step): array => [$step->number, $step->buildsOn, $step->requires], $plan->steps);
}

it('only lets steps build on earlier steps', function (): void {
    $plan = (new PlanSchema)->parse(['steps' => [
        stepEntry('Outline', [1, 2, 3]),
        stepEntry('Draft', [1, 1, 0, 2, 9]),
        stepEntry('Final', [2]),
    ]]);

    expect(shape($plan))->toBe([[1, [], []], [2, [1], []], [3, [2], []]]);
});

it('only lets essential questions hold a step back', function (): void {
    $plan = (new PlanSchema([2]))->parse(['steps' => [
        stepEntry('Outline'),
        stepEntry('Reply', [1], [1, 2, 2, 7]),
    ]]);

    expect(shape($plan))->toBe([[1, [], []], [2, [1], [2]]]);
});

it('makes the final step build on everything before it when the model said nothing', function (): void {
    $plan = (new PlanSchema)->parse(['steps' => [stepEntry('A'), stepEntry('B'), stepEntry('Final')]]);

    expect(shape($plan)[2])->toBe([3, [1, 2], []]);
});

it('always leaves at least one step that can start right away', function (): void {
    $plan = (new PlanSchema([1]))->parse(['steps' => [stepEntry('Only', [], [1])]]);

    expect(shape($plan))->toBe([[1, [], []]]);
});

it('keeps at most five steps and rejects an empty plan', function (): void {
    $plan = (new PlanSchema)->parse(['steps' => array_map(fn (int $n): array => stepEntry("Step {$n}"), range(1, 7))]);

    expect($plan->steps)->toHaveCount(5)
        ->and($plan->steps[0]->output)->toBe(OutputFormat::Draft)
        ->and(fn () => (new PlanSchema)->parse(['steps' => []]))->toThrow(InvalidModelOutput::class);
});

it('reads a review and renders it as the agent review piece', function (): void {
    $review = (new ReviewSchema)->parse([
        'verdict' => 'almost',
        'summary' => 'A warm note.',
        'strengths' => ['Warm', 'Short', 'Clear', 'Extra'],
        'issues' => [['where' => 'Note', 'problem' => 'No name', 'suggestion' => 'Add it'], ['where' => '', 'problem' => 'x', 'suggestion' => '']],
        'missing' => ['The name'],
        'nextActions' => ['Fill in the name'],
    ]);

    expect($review->verdict)->toBe(ReviewVerdict::Almost)
        ->and($review->strengths)->toHaveCount(3)
        ->and($review->issues)->toHaveCount(1)
        ->and($review->toMarkdown())->toMatchSnapshot();
});

it('finds the JSON object inside a chatty answer', function (): void {
    $text = "Sure! Here it is:\n```json\n{\"verdict\":\"ready\",\"summary\":\"Done.\",\"strengths\":[],\"issues\":[],\"missing\":[],\"nextActions\":[]}\n```";

    expect((new StructuredOutput)->decode($text, new ReviewSchema)->verdict)->toBe(ReviewVerdict::Ready);
});

it('rejects answers without a complete JSON object', function (string $text, string $message): void {
    expect(fn () => (new StructuredOutput)->decode($text, new ReviewSchema))->toThrow(InvalidModelOutput::class, $message);
})->with([
    ['No JSON here.', 'did not return JSON'],
    ['{"verdict": "ready" "summary": "x"}', 'incomplete JSON'],
]);
