<?php

declare(strict_types=1);

namespace App\Agent\Schemas;

use App\Agent\Limits;
use App\Agent\Results\Interpretation;
use App\Agent\Results\InterpretedQuestion;
use App\Enums\Deliverable;
use App\Enums\QuestionKind;
use App\Inference\OutputSchema;

/** @implements StructuredSchema<Interpretation> */
final class InterpretationSchema implements StructuredSchema
{
    public function format(): OutputSchema
    {
        return new OutputSchema('interpretation', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'language', 'deliverable', 'outcome', 'context', 'assumptions', 'questions'],
            'properties' => [
                'title' => ['type' => 'string'],
                'language' => ['type' => 'string'],
                'deliverable' => ['type' => 'string', 'enum' => array_column(Deliverable::cases(), 'value')],
                'outcome' => ['type' => 'string'],
                'context' => ['type' => 'array', 'items' => ['type' => 'string']],
                'assumptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['question', 'why', 'kind'],
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => array_column(QuestionKind::cases(), 'value')],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function parse(array $data): Interpretation
    {
        $fields = new Fields;
        $title = $fields->text($data, 'title', '$');
        $outcome = $fields->text($data, 'outcome', '$');
        $deliverable = $fields->choice($data, 'deliverable', '$', Deliverable::class);
        $context = $fields->texts($data, 'context', '$');
        $assumptions = $fields->texts($data, 'assumptions', '$');
        $questions = [];

        foreach ($fields->objects($data, 'questions', '$') as $index => $entry) {
            $text = is_string($entry['question'] ?? null) ? trim($entry['question']) : '';

            if ($text === '') {
                continue;
            }

            $kind = $fields->choice($entry, 'kind', "\$.questions[{$index}]", QuestionKind::class);
            $why = is_string($entry['why'] ?? null) ? trim($entry['why']) : '';

            if ($kind !== null) {
                $questions[] = new InterpretedQuestion($text, $why, $kind);
            }
        }

        $fields->assertValid();

        return new Interpretation(
            title: mb_substr($title, 0, Limits::MAX_TITLE_CHARACTERS),
            language: $this->language($data['language'] ?? null),
            deliverable: $deliverable ?? Deliverable::Other,
            outcome: $outcome,
            context: array_slice($context, 0, Limits::MAX_CONTEXT_FACTS),
            assumptions: array_slice($assumptions, 0, Limits::MAX_ASSUMPTIONS),
            questions: array_slice($questions, 0, Limits::MAX_QUESTIONS),
        );
    }

    private function language(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $tag = trim($value);

        return preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $tag) === 1 ? $tag : null;
    }
}
