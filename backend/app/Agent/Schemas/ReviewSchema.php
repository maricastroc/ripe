<?php

declare(strict_types=1);

namespace App\Agent\Schemas;

use App\Agent\Results\Review;
use App\Agent\Results\ReviewIssue;
use App\Enums\ReviewVerdict;
use App\Inference\OutputSchema;

/** @implements StructuredSchema<Review> */
final class ReviewSchema implements StructuredSchema
{
    public function format(): OutputSchema
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return new OutputSchema('review', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['verdict', 'summary', 'strengths', 'issues', 'missing', 'nextActions'],
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => array_column(ReviewVerdict::cases(), 'value')],
                'summary' => ['type' => 'string'],
                'strengths' => $list,
                'issues' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['where', 'problem', 'suggestion'],
                        'properties' => [
                            'where' => ['type' => 'string'],
                            'problem' => ['type' => 'string'],
                            'suggestion' => ['type' => 'string'],
                        ],
                    ],
                ],
                'missing' => $list,
                'nextActions' => $list,
            ],
        ]);
    }

    public function parse(array $data): Review
    {
        $fields = new Fields;
        $verdict = $fields->choice($data, 'verdict', '$', ReviewVerdict::class);
        $summary = $fields->text($data, 'summary', '$');
        $issues = [];

        foreach ($fields->objects($data, 'issues', '$') as $entry) {
            $where = is_string($entry['where'] ?? null) ? trim($entry['where']) : '';
            $problem = is_string($entry['problem'] ?? null) ? trim($entry['problem']) : '';
            $suggestion = is_string($entry['suggestion'] ?? null) ? trim($entry['suggestion']) : '';

            if ($where !== '' && $problem !== '') {
                $issues[] = new ReviewIssue($where, $problem, $suggestion);
            }
        }

        $strengths = $fields->texts($data, 'strengths', '$');
        $missing = $fields->texts($data, 'missing', '$');
        $nextActions = $fields->texts($data, 'nextActions', '$');
        $fields->assertValid();

        return new Review(
            verdict: $verdict ?? ReviewVerdict::Draft,
            summary: $summary,
            strengths: array_slice($strengths, 0, 3),
            issues: array_slice($issues, 0, 4),
            missing: $missing,
            nextActions: array_slice($nextActions, 0, 4),
        );
    }
}
