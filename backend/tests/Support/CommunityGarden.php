<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\ModelRequest;

final class CommunityGarden
{
    public const string PROMPT = "Write the project proposal for our neighborhood community garden for the city's small grants program. The maximum grant is $8,000.";

    private const array PIECES = [
        'Proposal outline' => <<<'MD'
            ## 1. Summary
            - What the garden is, where it is and who takes part (three or four lines).

            ## 2. Problem
            - Vacant lot on [[TBD: street or square name]].
            - Little access to fresh food and few places for neighbors to meet.

            ## 3. Goals
            - Turn the lot into a shared garden run by residents.
            - Offer monthly growing workshops for kids and adults.

            ## 4. How it will work
            - Work days, a care rota and simple rules for the beds.

            ## 5. Budget and timeline
            - Items, amounts and phases over six months.

            ## 6. Signatory
            - Person responsible and contact.
            MD,
        'Draft: problem and goals' => <<<'MD'
            ## Problem

            For years the lot on [[TBD: street or square name]] has been unused. It collects trash, is dark at night and keeps people away. At the same time, many residents say it is hard to buy fresh vegetables close to home and that families have few places to get together.

            ## Goals

            1. **Restore the lot**, clearing it and preparing beds for a shared garden.
            2. **Grow fresh food** for the families who help look after it.
            3. **Create a meeting point**, with monthly growing workshops open to the neighborhood.
            4. **Make it last**, with a care rota and a few simple rules agreed by the participants.
            MD,
        'Budget and timeline' => <<<'MD'
            ## Budget (cap of $8,000)

            | Item | Estimate |
            | --- | --- |
            | Clearing and preparing the lot | $1,800 |
            | Soil, compost and seedlings | $2,200 |
            | Tools and watering cans | $1,400 |
            | Simple irrigation system | $1,600 |
            | Workshop materials | $1,000 |
            | **Total** | **$8,000** |

            ## Timeline

            - [ ] **Month 1:** clean-up day and residents' meeting
            - [ ] **Month 2:** build the beds and install irrigation
            - [ ] **Month 3:** first planting and care rota
            - [ ] **Months 4 to 6:** monthly workshops and first harvest
            MD,
        'Full proposal' => <<<'MD'
            # Neighborhood Community Garden

            ## Summary
            We propose turning the vacant lot on [[TBD: street or square name]] into a shared garden cared for by residents, with monthly workshops open to the neighborhood. We are requesting **$8,000** for six months of setup.

            ## Problem
            The lot has been unused for years, collects trash and keeps people away. Fresh food is hard to find nearby and families have few places to spend time together.

            ## Goals
            Restore the lot, grow fresh food, create a meeting point and keep it going with a care rota agreed among the participants.

            ## How it will work
            The beds will be tended on work days and through a weekly rota. Each participating family takes one shift per month. The rules for using the garden will be set at the first meeting.

            ## Budget and timeline
            The budget covers preparing the lot, supplies, tools, irrigation and workshops, totaling $8,000. Setup takes six months, from the clean-up day to the first harvest.

            ## Signatory
            [[TBD: name and contact of the person signing]]
            MD,
    ];

    public function reply(ModelRequest $request): FakeReply
    {
        return match ($request->schema?->name) {
            'interpretation' => FakeReply::json([
                'title' => 'Community garden proposal',
                'language' => 'en',
                'deliverable' => 'document',
                'outcome' => 'A complete grant proposal covering the problem, goals, how it will work, budget and timeline.',
                'context' => ["It's for the city's small grants program.", 'The maximum grant is $8,000.'],
                'assumptions' => ['Six-month setup.', 'Plain language, no jargon.'],
                'questions' => [
                    ['question' => 'Where is the lot?', 'why' => 'The location appears in the summary and the problem.', 'kind' => 'optional'],
                    ['question' => 'Who signs the proposal?', 'why' => 'The grant requires a responsible person.', 'kind' => 'optional'],
                ],
            ]),
            'plan' => FakeReply::json(['steps' => [
                ['title' => 'Proposal outline', 'description' => 'Skeleton of the sections the proposal needs.', 'output' => 'outline', 'buildsOn' => [], 'requires' => []],
                ['title' => 'Draft: problem and goals', 'description' => 'Prose for the problem and goals sections.', 'output' => 'draft', 'buildsOn' => [1], 'requires' => []],
                ['title' => 'Budget and timeline', 'description' => 'Cost table and a six-month plan.', 'output' => 'schedule', 'buildsOn' => [1], 'requires' => []],
                ['title' => 'Full proposal', 'description' => 'Integrated version, ready to review and submit.', 'output' => 'draft', 'buildsOn' => [1, 2, 3], 'requires' => []],
            ]]),
            'review' => FakeReply::json([
                'verdict' => 'almost',
                'summary' => 'The proposal has a clear structure and a budget that matches the stated cap. Two details that only you know are still missing before it can be submitted.',
                'strengths' => ['Concrete goals that are easy to evaluate.', 'A detailed budget consistent with the amount requested.'],
                'issues' => [[
                    'where' => 'Full proposal',
                    'problem' => 'The "How it will work" section is still generic.',
                    'suggestion' => 'Mention how many families have shown interest, if you know.',
                ]],
                'missing' => ['The name of the street or square where the lot is.', 'The name and contact of whoever signs.'],
                'nextActions' => ['Fill in where the lot is', 'Decide who signs the proposal', 'Read the full version out loud once'],
            ]),
            default => FakeReply::text($this->piece($request)),
        };
    }

    private function piece(ModelRequest $request): string
    {
        $input = $request->messages[0]->content ?? '';
        $title = preg_match('/Now produce step \d+: "([^"]+)"/', $input, $matches) === 1 ? $matches[1] : 'Full proposal';

        return self::PIECES[$title] ?? self::PIECES['Full proposal'];
    }
}
