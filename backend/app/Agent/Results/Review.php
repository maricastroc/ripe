<?php

declare(strict_types=1);

namespace App\Agent\Results;

use App\Enums\ReviewVerdict;

final readonly class Review
{
    /**
     * @param  list<string>  $strengths
     * @param  list<ReviewIssue>  $issues
     * @param  list<string>  $missing
     * @param  list<string>  $nextActions
     */
    public function __construct(
        public ReviewVerdict $verdict,
        public string $summary,
        public array $strengths,
        public array $issues,
        public array $missing,
        public array $nextActions,
    ) {}

    public function toMarkdown(string $locale = 'en'): string
    {
        $parts = ['**'.__('Verdict:', [], $locale).'** '.$this->verdict->label($locale).'.', $this->summary];

        if ($this->strengths !== []) {
            $parts[] = '## '.__('What already works', [], $locale)."\n".$this->bullets($this->strengths);
        }

        if ($this->issues !== []) {
            $parts[] = '## '.__('What to improve', [], $locale)."\n".implode("\n", array_map(
                static fn (ReviewIssue $issue): string => "- **{$issue->where}:** {$issue->problem}".($issue->suggestion === '' ? '' : " → {$issue->suggestion}"),
                $this->issues,
            ));
        }

        if ($this->missing !== []) {
            $parts[] = '## '.__('What still depends on you', [], $locale)."\n".$this->bullets($this->missing);
        }

        if ($this->nextActions !== []) {
            $parts[] = '## '.__('Next steps when you come back', [], $locale)."\n".implode("\n", array_map(static fn (string $action): string => "- [ ] {$action}", $this->nextActions));
        }

        return implode("\n\n", $parts);
    }

    /** @param list<string> $items */
    private function bullets(array $items): string
    {
        return implode("\n", array_map(static fn (string $item): string => "- {$item}", $items));
    }
}
