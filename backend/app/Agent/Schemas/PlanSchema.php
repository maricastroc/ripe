<?php

declare(strict_types=1);

namespace App\Agent\Schemas;

use App\Agent\Limits;
use App\Agent\Results\Plan;
use App\Agent\Results\PlannedStep;
use App\Enums\OutputFormat;
use App\Inference\OutputSchema;

/** @implements StructuredSchema<Plan> */
final class PlanSchema implements StructuredSchema
{
    /** @param list<int> $essentialQuestions */
    public function __construct(private readonly array $essentialQuestions = []) {}

    public function format(): OutputSchema
    {
        return new OutputSchema('plan', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['steps'],
            'properties' => [
                'steps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'description', 'output', 'buildsOn', 'requires'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'output' => ['type' => 'string', 'enum' => array_column(OutputFormat::cases(), 'value')],
                            'buildsOn' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'requires' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function parse(array $data): Plan
    {
        $fields = new Fields;
        $steps = [];

        foreach ($fields->objects($data, 'steps', '$') as $index => $entry) {
            if (count($steps) === Limits::MAX_STEPS) {
                break;
            }

            $path = "\$.steps[{$index}]";
            $number = count($steps) + 1;
            $title = $fields->text($entry, 'title', $path);
            $output = $fields->choice($entry, 'output', $path, OutputFormat::class);

            if ($title === '' || $output === null) {
                continue;
            }

            $steps[] = new PlannedStep(
                number: $number,
                title: mb_substr($title, 0, Limits::MAX_STEP_TITLE_CHARACTERS),
                description: is_string($entry['description'] ?? null) ? trim($entry['description']) : '',
                output: $output,
                buildsOn: $this->earlier($fields->numbers($entry, 'buildsOn', $path), $number),
                requires: $this->essential($fields->numbers($entry, 'requires', $path)),
            );
        }

        if ($steps === []) {
            $fields->problem('$.steps must contain at least one step');
        }

        $fields->assertValid();

        return new Plan($this->guaranteeProgress($this->finalBuildsOnEverything($steps)));
    }

    /**
     * @param  list<int>  $numbers
     * @return list<int>
     */
    private function earlier(array $numbers, int $current): array
    {
        $earlier = array_values(array_unique(array_filter($numbers, static fn (int $n): bool => $n >= 1 && $n < $current)));
        sort($earlier);

        return $earlier;
    }

    /**
     * @param  list<int>  $numbers
     * @return list<int>
     */
    private function essential(array $numbers): array
    {
        $essential = array_values(array_unique(array_filter($numbers, fn (int $n): bool => in_array($n, $this->essentialQuestions, true))));
        sort($essential);

        return $essential;
    }

    /**
     * @param  list<PlannedStep>  $steps
     * @return list<PlannedStep>
     */
    private function finalBuildsOnEverything(array $steps): array
    {
        $count = count($steps);

        if ($count < 2 || $steps[$count - 1]->buildsOn !== []) {
            return $steps;
        }

        $final = $steps[$count - 1];

        return [...array_slice($steps, 0, -1), new PlannedStep($final->number, $final->title, $final->description, $final->output, range(1, $count - 1), $final->requires)];
    }

    /**
     * @param  list<PlannedStep>  $steps
     * @return list<PlannedStep>
     */
    private function guaranteeProgress(array $steps): array
    {
        foreach ($steps as $step) {
            if ($step->buildsOn === [] && $step->requires === []) {
                return $steps;
            }
        }

        $first = $steps[0];

        return [new PlannedStep($first->number, $first->title, $first->description, $first->output, $first->buildsOn, []), ...array_slice($steps, 1)];
    }
}
